<?php

use App\Http\Controllers\SignatureController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::options('{any}', function () {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
})->where('any', '.*');

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/', [SignatureController::class, 'index']);
Route::get('/signature/{codigoVerificao}', [SignatureController::class, 'signature']);
Route::get('/document/{id_documento}', [SignatureController::class, 'viewDocument'])->name('api.document.view');
Route::get('/view-document/{id_documento}', [SignatureController::class, 'showDocumentViewer']);
Route::get('/signed-document/{codigo_transacao}', [SignatureController::class, 'viewSignedDocument']);
Route::get('/verifySignature', [SignatureController::class, 'verifySignature']);
