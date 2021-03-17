<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CandidatoController;
use App\Http\Controllers\LoteController;
use App\Http\Controllers\PostoVacinacaoController;
use App\Http\Controllers\EtapaController;


/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
})->name('index');

Route::get('/dashboard',  [CandidatoController::class, 'show'])->middleware(['auth'])->name('dashboard');

Route::get("/solicitar", [CandidatoController::class, 'solicitar'])->name("solicitacao.candidato");
Route::post("/solicitar/enviar", [CandidatoController::class, 'enviar_solicitacao'])->name("solicitacao.candidato.enviar");
Route::post("/agendamento/{id}/confirmacao", [CandidatoController::class, 'update'])->name("update.agendamento")->middleware(['auth']);

Route::get("/cep/{cep}", function($cep) {
    //TODO: mover isso pra um controller
    $results = simplexml_load_file("http://cep.republicavirtual.com.br/web_cep.php?formato=xml&cep=" . $cep);
    return response()->json($results);
});


Route::resource('/postos', PostoVacinacaoController::class);
Route::resource('/lotes', LoteController::class);
Route::resource('/etapas', EtapaController::class);

//Route::get('/lotes', [LoteController::class, 'show'])->name('lotes')->middleware(['auth']);


require __DIR__.'/auth.php';
