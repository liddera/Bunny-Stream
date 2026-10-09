<?php

return [

    /*
    | Biblioteca do Bunny Stream usada nos testes. Os valores vêm do .env;
    | nenhuma chave fica no código.
    */
    'library_id' => env('BUNNY_LIBRARY_ID'),

    // Chave da aba API da biblioteca: cria, consulta e apaga vídeos e assina o envio TUS.
    'api_key' => env('BUNNY_API_KEY'),

    // Chave somente leitura da mesma aba: é o segredo que assina o webhook.
    'readonly_api_key' => env('BUNNY_READONLY_API_KEY'),

    // Chave da aba Security > "Token authentication key": assina o link do player.
    'token_key' => env('BUNNY_TOKEN_KEY'),

    // Ex.: vz-71feacea-0ca.b-cdn.net (miniaturas e arquivos).
    'cdn_hostname' => env('BUNNY_CDN_HOSTNAME'),

    'api_base' => 'https://video.bunnycdn.com',
    'tus_endpoint' => 'https://video.bunnycdn.com/tusupload',
    'player_base' => 'https://player.mediadelivery.net/embed',

    // Validade, em segundos, das assinaturas geradas aqui.
    'tus_ttl' => (int) env('BUNNY_TUS_TTL', 3600),
    'embed_ttl' => (int) env('BUNNY_EMBED_TTL', 300),
];
