<?php

namespace App\Http\Controllers;

use Throwable;
use DateInterval;
use Carbon\Carbon;
use App\Models\Lote;
use App\Models\Etapa;
use App\Models\Candidato;
use Illuminate\Http\Request;
use App\Models\PostoVacinacao;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use App\Notifications\CandidatoAprovado;
use App\Notifications\CandidatoInscrito;
use App\Notifications\CandidatoReprovado;
use App\Models\Configuracao;
use Illuminate\Support\Facades\Notification;
use App\Notifications\CandidatoInscritoSegundaDose;


class CandidatoController extends Controller
{
    public function show(Request $request) {

        $query = Candidato::query();

        if ($request->nome_check && $request->nome != null) {
            $query->where('nome_completo', 'ilike', '%' . $request->nome . '%');
        }

        if ($request->cpf_check && $request->cpf != null) {
            $query->where('cpf', $request->cpf);
        }

        if ($request->data_check && $request->data != null) {
            $amanha = (new Carbon($request->data))->addDays(1);
            $query->where([['chegada','>=',$request->data], ['chegada','<=', $amanha]]);
        } 

        if ($request->dose_check && $request->dose != null) {
            $query->where('dose',$request->dose);
        }

        if ($request->aprovado) {
            $query->where('aprovacao', Candidato::APROVACAO_ENUM[1]);
        }

        if ($request->reprovado) {
            $query->where('aprovacao', Candidato::APROVACAO_ENUM[2]);
        }

        $agendamentos = $query->get();

        if ($request->outro) {
            $agendamentosComOutrasInfo = collect();

            foreach ($agendamentos as $agendamento) {
                $outros = $agendamento->outrasInfo;
                if($outros != null && count($outros) > 0) {
                    $agendamentosComOutrasInfo->push($agendamento);
                }
            }

            if ($agendamentosComOutrasInfo->count() > 0) {
                $agendamentos = $agendamentosComOutrasInfo;
            } else {
                $agendamentos = collect();
            }
        }

        return view('dashboard')->with(['candidatos' => $agendamentos,
                                        'candidato_enum' => Candidato::APROVACAO_ENUM,
                                        'tipos' => Etapa::TIPO_ENUM,
                                        'doses' => Candidato::DOSE_ENUM,
                                        'request' => $request]);
    }

    // public function pendentes(Request $request) {
    //     $candidatos = null;

    //     if($request->filtro == null || $request->filtro == 1) {
    //         $candidatos = Candidato::where('aprovacao', Candidato::APROVACAO_ENUM[0])->paginate(30);
    //     }

    //     return view('dashboard')->with(['candidatos' => $candidatos,
    //     'candidato_enum' => Candidato::APROVACAO_ENUM,
    //     'tipos' => Etapa::TIPO_ENUM,
    //     'filtro' => $request->filtro]);
    // }

    public function solicitar() {

        // TODO: pegar s?? os postos com vacinas disponiveis
        $postos_com_vacina = PostoVacinacao::where('padrao_no_formulario', true)->get();
        $etapasAtuais = Etapa::where('atual', true)->orderBy('texto')->get();
        $config = Configuracao::first();

        $bairros = [
            "Magano",
            "Dom H??lder C??mara",
            "Dom Thiago Postma",
            "S??o Jos??",
            "Santo Ant??nio",
            "Alo??sio Pinto",
            "Boa Vista",
            "Francisco Figueira",
            "Heli??polis",
            "Jos?? Maria Dourado",
            "Novo Heli??polis",
            "Severiano Moraes Filho",
            "Manoel Ch??u",
        ];

        return view("form_solicitacao")->with([
            "sexos" => Candidato::SEXO_ENUM,
            "postos" => $postos_com_vacina,
            "doses" => Candidato::DOSE_ENUM,
            "publicos" => $etapasAtuais,
            "tipos"    => Etapa::TIPO_ENUM,
            "bairros" => $bairros,
            "config"    => $config,
        ]);

    }
    public function ver($id) {
        return view("ver_agendamento", ["agendamento" => Candidato::find($id)]);
    }

    public function enviar_solicitacao(Request $request) {

        $request->validate([
            "voltou"                => "nullable",
            "p??blico"               => "required",
            "nome_completo"         => "required|string|min:8|max:65|regex:/^[\pL\s]+$/u",
            "data_de_nascimento"    => "required|date|before:today",
            "cpf"                   => "required",
            "n??mero_cart??o_sus"     => "required",
            "sexo"                  => "required",
            "nome_da_m??e"           => "required|string|min:8|max:65|regex:/^[\pL\s]+$/u",
            "telefone"              => "required",
            "whatsapp"              => "nullable",
            "email"                 => "nullable|email",
            "cep"                   => "nullable",
            // "cidade"                => "required", // como valor ?? fixado no front, pode ser desabilitado e hardcoded aqui no controller
            "bairro"                => "required",
            "rua"                   => "required|regex:/[a-zA-Z0-9\s]+/|min:5", // Na cohab 2, as pessoas n??o sabem os nomes das ruas, s?? os numeros, ent??o tem gente que vai por "Rua 2"
            "n??mero_residencial"    => "required|regex:/[a-zA-Z0-9\s]+/",
            "complemento_endereco"  => "nullable",
            "posto_vacinacao"       => "required",
            "dia_vacinacao"         => "required",
            "horario_vacinacao"     => "required",
            "opcao_etapa_".$request->input('p??blico') => 'nullable',
        ]);

        $dados = $request->all();

        DB::beginTransaction();

        try {
            if (Candidato::where([['cpf', $request->cpf], ['aprovacao', Candidato::APROVACAO_ENUM[0]]])->get()->count() > 0) {
                return redirect()->back()->withErrors([
                    "cpf" => "Existe um agendamento pendente para esse CPF."
                ])->withInput();
            }


            $candidato = new Candidato;
            $candidato->nome_completo           = $request->nome_completo;
            $candidato->data_de_nascimento      = $request->data_de_nascimento;
            $candidato->cpf                     = $request->cpf;
            $candidato->numero_cartao_sus       = $request->input("n??mero_cart??o_sus");
            $candidato->sexo                    = $request->sexo;
            $candidato->nome_da_mae             = $request->input("nome_da_m??e");
            $candidato->telefone                = $request->telefone;
            $candidato->whatsapp                = $request->whatsapp;
            $candidato->email                   = $request->email;
            $candidato->cep                     = preg_replace('/[^0-9]/', '', $request->cep);
            // $candidato->cidade                  = $request->cidade;
            $candidato->cidade                  = "Garanhuns";
            $candidato->bairro                  = $request->bairro;
            $candidato->logradouro              = $request->rua;
            $candidato->numero_residencia       = $request->input("n??mero_residencial");
            $candidato->complemento_endereco    = $request->complemento_endereco;
            $candidato->aprovacao               = Candidato::APROVACAO_ENUM[0];
            $candidato->dose                    = Candidato::DOSE_ENUM[0];

            // Se n??o foi passado CEP, o preg_replace retorna string vazia, mas no bd ?? uint nulavel, ent??o anula
            if ($candidato->cep == "") {
                $candidato->cep = NULL;
            }

            // Relacionar o candidato com o p??blico escolhido e realiza
            // a valida????o de acordo com o p??blico escolhido
            $idade              = $this->idade($request->data_de_nascimento);
            $candidato->idade   = $idade;

            $etapa = Etapa::find($request->input('p??blico'));

            if ($etapa->tipo == Etapa::TIPO_ENUM[0]) {
                if (!($etapa->inicio_intervalo <= $idade && $etapa->fim_intervalo >= $idade)) {
                    return redirect()->back()->withErrors([
                        "data_de_nascimento" => "Idade fora da faixa et??ria de vacina????o."
                    ])->withInput();
                }
            } else if ($etapa->tipo == Etapa::TIPO_ENUM[2]) {
                if ($request->input("publico_opcao_" . $request->input('p??blico')) == null) {
                    return redirect()->back()->withErrors([
                        "publico_opcao_" . $request->input('p??blico') => "Esse campo ?? obrigat??rio para p??blico marcado."
                    ])->withInput();
                }
                $candidato->etapa_resultado = $request->input("publico_opcao_" . $request->input('p??blico'));
            }

            $candidato->etapa_id = $etapa->id;
            //TODO: mover pro service provider
            if (!$this->validar_cpf($request->cpf)) {
                return redirect()->back()->withErrors([
                    "cpf" => "N??mero de CPF inv??lido"
                ])->withInput();
            }

            // if(Candidato::where('cpf',$request->cpf )->contains()) {
            //     return redirect()->back()->withErrors([
            //         "cpf" => "N??mero de CPF inv??lido"
            //     ])->withInput();
            // }

            if (!$this->validar_telefone($request->telefone)) {
                return redirect()->back()->withErrors([
                    "telefone" => "N??mero de telefone inv??lido"
                ])->withInput();
            }

            $dia_vacinacao = $request->dia_vacinacao;
            $horario_vacinacao = $request->horario_vacinacao;
            $id_posto = $request->posto_vacinacao;
            $datetime_chegada = Carbon::createFromFormat("d/m/Y H:i", $dia_vacinacao . " " . $horario_vacinacao);
            $datetime_saida = $datetime_chegada->copy()->addMinutes(10);

            $candidatos_no_mesmo_horario_no_mesmo_lugar = Candidato::where("chegada", "=", $datetime_chegada)->where("posto_vacinacao_id", $id_posto)->get();

            if ($candidatos_no_mesmo_horario_no_mesmo_lugar->count() > 0) {
                return redirect()->back()->withErrors([
                    "posto_vacinacao" => "Algu??m conseguiu preencher o formul??rio mais r??pido que voc??, escolha outro horario por favor."
                ])->withInput();
            }

            $etapa = Etapa::where('id',$request->input('p??blico'))->first();

            if(!$etapa->lotes->count()){
                return redirect()->back()->withErrors([
                    "posto_vacinacao" => "N??o h?? mais doses dispon??veis. Favor realize o seu cadastro na fila de espera pela p??gina principal."
                ])->withInput();
            }
            //Retorna um array de IDs do lotes associados a etapa escolhida
            $array_lotes_disponiveis = $etapa->lotes->pluck('id');


            // Pega a lista de todos os lotes da etapa escolhida para o posto escolhido
            $lotes_disponiveis = DB::table("lote_posto_vacinacao")->where("posto_vacinacao_id", $id_posto)
                                    ->whereIn('lote_id', $array_lotes_disponiveis)->get();

            $id_lote = 0;

            // Pra cada lote que esteje no posto
            foreach ($lotes_disponiveis as $lote) {

                // Se a quantidade de candidatos ?? tomar a vicina daquele lote, naquele posto, que n??o foram reprovados
                // for menor que a quantidade de vacinas daquele lote que foram pra aquele posto, ent??o o candidato vai tomar
                // daquele lote

                $lote_original = Lote::find($lote->lote_id);
                $qtdCandidato = Candidato::where("lote_id", $lote->id)->where("posto_vacinacao_id", $id_posto)->where("aprovacao", "!=", Candidato::APROVACAO_ENUM[2])
                                            ->count();
                if(!$lote_original->dose_unica){
                    //Se o lote disponivel for de vacina com dose dupla vai parar aqui
                    //e verifica se tem duas vacinas disponiveis
                    if (($qtdCandidato + 1) < $lote->qtdVacina) {
                        $id_lote = $lote->id;
                        $chave_estrangeiro_lote = $lote->lote_id;
                        $qtd = $lote->qtdVacina - $qtdCandidato;

                        if ( !$lote_original->dose_unica && !($qtd >= 2) ) {
                            return redirect()->back()->withErrors([
                                "posto_vacinacao" => "N??o h?? mais doses dispon??veis. Favor realize o seu cadastro na fila de espera pela p??gina principal."
                            ])->withInput();
                        }
                        break;
                    }

                }else{
                    //Se o lote disponivel for de vacina com dose unica vai parar aqui
                    //e verifica se tem pelo menos uma ou mais vacinas disponiveis
                    if ($qtdCandidato < $lote->qtdVacina) {
                        $id_lote = $lote->id;
                        $chave_estrangeiro_lote = $lote->lote_id;
                        break;
                    }
                }

            }

            if ($id_lote == 0) { // Se ?? 0 ?? porque n??o tem vacinas...
                return redirect()->back()->withErrors([
                    "posto_vacinacao" => "N??o h?? mais doses dispon??veis. Favor realize o seu cadastro na fila de espera pela p??gina principal."
                ])->withInput();
            }

            $candidato->chegada                 = $datetime_chegada;
            $candidato->saida                   = $datetime_saida;
            $candidato->lote_id                 = $id_lote;
            $candidato->posto_vacinacao_id      = $id_posto;

            // $candidato->paciente_acamado = isset($dados["paciente_acamado"]);
            // $candidato->paciente_dificuldade_locomocao = isset($dados["paciente_dificuldade_locomocao"]);


            $candidato->save();

            $lote = Lote::find($chave_estrangeiro_lote);

            if (!$lote->dose_unica) {
                $datetime_chegada_segunda_dose = $candidato->chegada->add(new DateInterval('P'.$lote->inicio_periodo.'D'));
                if($datetime_chegada_segunda_dose->format('l') == "Sunday"){
                    $datetime_chegada_segunda_dose->add(new DateInterval('P1D'));
                }
                $candidatoSegundaDose = $candidato->replicate()->fill([
                    'chegada' =>  $datetime_chegada_segunda_dose,
                    'saida'   =>  $datetime_chegada_segunda_dose->copy()->addMinutes(10),
                    'dose'   =>  Candidato::DOSE_ENUM[1],
                ]);

                $candidatoSegundaDose->save();

                if ($etapa->outrasInfo != null && count($etapa->outrasInfo) > 0) {
                    if ($request->input("opcao_etapa_".$etapa->id) != null && count($request->input("opcao_etapa_".$etapa->id)) > 0) {
                        foreach ($request->input("opcao_etapa_".$etapa->id) as $outra_info_id) {
                            $candidatoSegundaDose->outrasInfo()->attach($outra_info_id);
                        }
                    }
                }

                if($candidatoSegundaDose->email != null){
                    Notification::send($candidatoSegundaDose, new CandidatoInscritoSegundaDose($candidatoSegundaDose, $lote ));
                }
            }

            if($candidato->email != null){
                Notification::send($candidato, new CandidatoInscrito($candidato, $lote));
            }


            if ($etapa->outrasInfo != null && count($etapa->outrasInfo) > 0) {
                if ($request->input("opcao_etapa_".$etapa->id) != null && count($request->input("opcao_etapa_".$etapa->id)) > 0) {
                    foreach ($request->input("opcao_etapa_".$etapa->id) as $outra_info_id) {
                        $candidato->outrasInfo()->attach($outra_info_id);
                    }
                }
            }

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollback();
            if(env('APP_DEBUG')){
                return redirect()->back()->withErrors([
                    "message" => $e->getMessage(),
                ])->withInput();
            }
            return redirect()->back()->withErrors([
                "message" => "Houve algum erro, entre em contato com a administra????o do site.",
            ])->withInput();
        }

        if(!Candidato::where('cpf', $candidato->cpf)->count()){
            return redirect()->back()->withErrors([
                "message" => "Houve algum erro, seu agendamento n??o,entre em contato com a administra????o do site.",
            ])->withInput();
        }

        $agendamentos = Candidato::where('cpf', $candidato->cpf)->orderBy('dose')->get();

        return view('comprovante')->with(['status' => 'Solicita????o realizada com sucesso!',
                                          'agendamentos' => $agendamentos,
                                          'aprovacao_enum' => Candidato::APROVACAO_ENUM,]);
    }

    public function comprovante()
    {
        return view('comprovante')->with('status', 'Cadastrado com sucesso');
    }

    public function uploadFile($request, $input, $nome){
    	if($request->hasFile($input)){
    		$path = $request->photo->storeAs('images', $nome, 'public');

    		return $path;
    	}
    	return null;
    }

    public function update(Request $request, $id) {
        Gate::authorize('confirmar-vaga-candidato');
        $validated = $request->validate([
            'confirmacao' => 'required'
        ]);

        $candidato = Candidato::find($id);
        $lote = DB::table("lote_posto_vacinacao")->where('id', $candidato->lote_id)->get();
        $lote = Lote::find($lote[0]->lote_id);
        // dd($lote);
        if($request->confirmacao == "Ausente"){
            $candidato = Candidato::find($id);
            $candidato->aprovacao = Candidato::APROVACAO_ENUM[2];
            $candidato->save();
            $candidato->delete();

        }elseif($request->confirmacao == "Aprovado"){
            $candidato = Candidato::find($id);
            $candidato->aprovacao = Candidato::APROVACAO_ENUM[1];
            $candidato->save();

            if($candidato->email != null){
                $lote = DB::table("lote_posto_vacinacao")->where('id', $candidato->lote_id)->get();
                $lote = Lote::find($lote[0]->lote_id);
                Notification::send($candidato, new CandidatoAprovado($candidato, $lote ));
            }
            $candidato->delete();
        }elseif($request->confirmacao == "Reprovado"){

            $candidato = Candidato::find($id);
            $candidato->aprovacao = Candidato::APROVACAO_ENUM[2];
            $candidato->save();
            if($candidato->email != null){
                $lote = DB::table("lote_posto_vacinacao")->where('id', $candidato->lote_id)->get();
                $lote = Lote::find($lote[0]->lote_id);
                Notification::send($candidato, new CandidatoReprovado($candidato, $lote ));
            }
            $candidato->delete();

        }

        return redirect()->back()->with(['mensagem' => 'Resposta salva com sucesso!']);
    }

    public function idade($data_nascimento) {
        $hoje = Carbon::today();
        return $hoje->diffInYears($data_nascimento);
    }

    public function vacinado($id) {
        Gate::authorize('vacinado-candidato');
        $candidato = Candidato::find($id);
        $candidato->aprovacao = Candidato::APROVACAO_ENUM[3];
        $candidato->update();

        $etapa = $candidato->etapa;
        if ($etapa != null) {
            if ($candidato->dose == Candidato::DOSE_ENUM[0]) {
                $etapa->total_pessoas_vacinadas_pri_dose += 1;
            } else if ($candidato->dose == Candidato::DOSE_ENUM[1]) {
                $etapa->total_pessoas_vacinadas_seg_dose += 1;
            }
            $etapa->update();
        }

        return redirect()->back()->with(['mensagem' => 'Confirma????o salva.']);
    }

    public function consultar(Request $request) {
        $validated = $request->validate([
            'consulta'              => "required",
            'cpf'                   => 'required',
            'data_de_nascimento'    => 'required'
        ]);

        if(!$this->validar_cpf($request->cpf)) {
            return redirect()->back()->withErrors([
               "cpf" => "N??mero de CPF inv??lido"
           ])->withInput($validated);
        }

        $agendamentos = Candidato::where([['cpf', $request->cpf], ['data_de_nascimento', $request->data_de_nascimento]])
                      ->orderBy("dose") // Mostra primeiro o agendamento mais recente
                      ->get();

        if ($agendamentos->count() == 0) {
            return redirect()->back()->withErrors([
                "cpf" => "Dados n??o encontrados"
            ])->withInput($validated);
        }

        return view("comprovante")->with(["status" => "Resultado da consulta", "agendamentos" => $agendamentos, 'aprovacao_enum' => Candidato::APROVACAO_ENUM,]);
    }

    public function CandidatoLote()
    {
        $candidatos = Candidato::all();
        return view('candidatoLote', compact('candidatos'));
    }

    public function ordenar($field ,$order)
    {

        $candidatos = Candidato::orderBy($field, $order)->get();

        return view('dashboard')->with(['candidatos' => $candidatos,
                                        'candidato_enum' => Candidato::APROVACAO_ENUM,
                                        'tipos' => Etapa::TIPO_ENUM]);

    }
    public function ordenarNovaLista($field ,$order)
    {

        $candidatos = Candidato::orderBy($field, $order)->get();

        return view('agendamentos.apendentes')->with(['candidatos' => $candidatos,
                                        'candidato_enum' => Candidato::APROVACAO_ENUM,
                                        'tipos' => Etapa::TIPO_ENUM]);

    }

    public function filtro($field ,$tipo)
    {

        if($tipo == "Chegada"){
            $candidatos = Candidato::where('chegada','like',date("Y-m-d")."%")->get();
        }else{

            $candidatos = Candidato::where($field, $tipo)->get();
        }


        return back()->with(['candidatos' => $candidatos,
                                        'candidato_enum' => Candidato::APROVACAO_ENUM,
                                        'tipos' => Etapa::TIPO_ENUM]);

    }

    public function filtroAjax(Request $request) {
        $query = Candidato::query();

        if ($request->nome_check && $request->nome != null) {
            $query->where('nome_completo', 'ilike', '%' . $request->nome . '%');
        }

        if ($request->cpf_check && $request->cpf != null) {
            $query->where('cpf', $request->cpf);
        }

        if ($request->data_check && $request->data != null) {
            $query->where('chegada','like',$request->data."%");
        }

        if ($request->dose_check && $request->dose != null) {
            $query->where('dose',$request->dose);
        }

        if ($request->aprovado) {
            $query->where('aprovacao', Candidato::APROVACAO_ENUM[1]);
        }

        if ($request->reprovado) {
            $query->withTrashed()->where('aprovacao', Candidato::APROVACAO_ENUM[2]);
        }

        $agendamentos = $query->orderBy('nome_completo')->get();

        if ($request->outro) {
            $agendamentosComOutrasInfo = collect();

            foreach ($agendamentos as $agendamento) {
                $outros = $agendamento->outrasInfo;
                if($outros != null && count($outros) > 0) {
                    $agendamentosComOutrasInfo;
                }
            }

            if ($agendamentosComOutrasInfo->count() > 0) {
                $agendamentos = $agendamentosComOutrasInfo;
            } else {
                $agendamentos = collect();
            }
        }

        return $agendamentos;
    }
}
