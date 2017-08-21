<?php

$types = array_keys(config('mediatheque.mimes'));

/**
 * Upload
 */
Route::group([
    'prefix' => 'upload',
    'middleware' => ['api']
], function () use ($types) {
    Route::post('/', [
        'as' => 'mediatheque.upload',
        'uses' => 'UploadController@index'
    ]);

    Route::post('/pull', [
        'as' => 'mediatheque.upload.pull',
        'uses' => 'UploadController@pull'
    ]);

    foreach ($types as $type) {
        Route::post('/'.$type, [
            'as' => 'mediatheque.upload.'.$type,
            'uses' => 'UploadController@'.$type
        ]);
    }
});

/**
 * Api
 */
Route::group([
    'prefix' => 'api',
    'middleware' => ['api']
], function () use ($types) {
    foreach ($types as $type) {
        Route::resource($type, studly_case($type).'Controller', [
            'except' => ['create', 'edit'],
            'names' => [
                'index' => 'mediatheque.api.'.$type.'.index',
                'show' => 'mediatheque.api.'.$type.'.show',
                'store' => 'mediatheque.api.'.$type.'.store',
                'update' => 'mediatheque.api.'.$type.'.update',
                'destroy' => 'mediatheque.api.'.$type.'.destroy'
            ]
        ]);
    }
});
