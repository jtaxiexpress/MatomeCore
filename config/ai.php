<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the AI providers below should be the
    | default for AI operations when no explicit provider is provided
    | for the operation. This should be any provider defined below.
    |
    */

    'default' => 'ollama',
    'default_for_images' => 'ollama',
    'default_for_audio' => 'ollama',
    'default_for_transcription' => 'ollama',
    'default_for_embeddings' => 'ollama',
    'default_for_reranking' => 'ollama',

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Below you may configure caching strategies for AI related operations
    | such as embedding generation. You are free to adjust these values
    | based on your application's available caching stores and needs.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
            'models' => [
                'text' => [
                    'default' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
                ],
            ],
        ],
        'ollama' => [
            'driver' => 'ollama',
            'url' => env('OLLAMA_BASE_URL', 'https://ollama.unicorn.tokyo'),
            'model' => env('OLLAMA_MODEL', 'gemma4:e2b'),
            'stream' => false,
            'options' => [
                'num_predict' => (int) env('OLLAMA_NUM_PREDICT', 3000),
                'num_ctx' => (int) env('OLLAMA_NUM_CTX', 8192),
                'temperature' => (float) env('OLLAMA_TEMPERATURE', 0.2),
                'repeat_penalty' => (float) env('OLLAMA_REPEAT_PENALTY', 1.0),
            ],
        ],
    ],

];
