<?php

/**
 * Catálogo de ações que podem ser liberadas para um grupo.
 * Cada chave vira um Gate (ex.: middleware "can:importacoes.importar").
 * Usuários de perfil "administrador" possuem todas as ações.
 */
return [
    'acoes' => [
        'avarias.registrar' => ['modulo' => 'Avarias', 'descricao' => 'Registrar avarias, enviar para aprovação e remover avarias pendentes (app do motorista)'],
        'avarias.aprovar' => ['modulo' => 'Avarias', 'descricao' => 'Aprovar ou reprovar avarias'],
        'avarias.editar_quantidade' => ['modulo' => 'Avarias', 'descricao' => 'Ajustar a quantidade avariada dos itens'],
        'avarias.reenviar_whatsapp' => ['modulo' => 'Avarias', 'descricao' => 'Reenviar a notificação de WhatsApp ao cliente'],
        'importacoes.importar' => ['modulo' => 'Importações', 'descricao' => 'Importar dados (clientes, motoristas, mapas, vendas e trocas)'],
        'mapas.designar_motorista' => ['modulo' => 'Mapas', 'descricao' => 'Designar ou trocar o motorista do mapa'],
        'mapas.alterar_clientes' => ['modulo' => 'Mapas', 'descricao' => 'Adicionar ou remover clientes do mapa'],
        'motoristas.gerenciar' => ['modulo' => 'Motoristas', 'descricao' => 'Cadastrar, editar e excluir motoristas'],
        'trocas.exportar' => ['modulo' => 'Vendas e Trocas', 'descricao' => 'Exportar relatório de vendas e trocas'],
        'usuarios.gerenciar' => ['modulo' => 'Administrativo', 'descricao' => 'Cadastrar, editar e excluir usuários'],
        'whatsapp.gerenciar' => ['modulo' => 'Administrativo', 'descricao' => 'Configurar integrações de WhatsApp'],
        'permissoes.gerenciar' => ['modulo' => 'Administrativo', 'descricao' => 'Gerenciar grupos, permissões e filiais dos usuários'],
    ],
];
