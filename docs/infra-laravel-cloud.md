# Infraestrutura no Laravel Cloud

Este guia reúne a configuração de produção do Hockey Game no Laravel Cloud. Valores sensíveis devem ser cadastrados apenas nas variáveis de ambiente do Cloud; nunca no repositório ou em um arquivo `.env` enviado ao servidor.

## Erros e rastreamento

Ative um rastreador antes de liberar a aplicação. O projeto já reconhece `NIGHTWATCH_ENABLED` nos testes; se optar pelo Laravel Nightwatch, configure a variável e a credencial/integração indicada pelo painel do Laravel Cloud. Como alternativa, pode-se usar Sentry: instale e configure-o em uma mudança própria, fornecendo somente `SENTRY_LARAVEL_DSN`, ambiente e taxa de amostragem pelo painel. Em ambos os casos, use `APP_ENV=production`, `APP_DEBUG=false` e confira que erros de uma página pública não registram token, e-mail ou payloads privados.

## Banco e backups

Habilite os backups gerenciados do banco no Laravel Cloud antes do primeiro deploy. Para este app, uma rotina diária com retenção de 30 dias é um ponto de partida razoável; mantenha também um backup mensal por 12 meses se o plano permitir. Teste restauração em um ambiente isolado pelo menos trimestralmente. Antes de uma alteração estrutural grande, crie um backup manual. Migrações desta aplicação são reversíveis quando possível, mas backup não deve ser substituído por rollback.

## Fotos e armazenamento persistente

Defina `MEDIA_DISK` para um bucket persistente (S3, R2 ou o storage compatível escolhido no Cloud), e configure as credenciais, região, bucket e URL/CDN correspondentes somente no ambiente. As fotos dos jogadores não devem depender do disco efêmero do container.

O service worker não deve pré-cachear fotos do bucket: elas podem ser muitas, mudar com frequência e consumir armazenamento do celular. Deixe o navegador fazer cache HTTP normalmente, com `Cache-Control` e URLs/versionamento do provedor. Só vale acrescentar cache sob demanda se houver uma necessidade offline comprovada, com limite de tamanho e rotina de limpeza.

## Cadastro, sessão e lembrar de mim

Em produção, mantenha `REGISTRATION_ENABLED=false` como padrão e libere cadastro apenas durante onboarding controlado, se necessário. Isso reduz contas desconhecidas em um app de times privados.

A sessão deve usar driver persistente compartilhado quando houver mais de uma instância (banco ou Redis), com `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax` e chave de aplicação estável. O recurso “lembrar de mim” é conveniente para o celular do responsável pelo time, mas deve continuar opcional: ele cria um cookie de longa duração e deve ser revogado ao trocar senha/sair. Não use sessão em arquivo em produção escalada.

## Deploy desta branch

1. Confirme as variáveis de produção, o bucket em `MEDIA_DISK`, backups e rastreamento de erros.
2. Faça o deploy normalmente pelo Laravel Cloud e execute as migrações no passo de release: `php artisan migrate --force`.
3. Compile os ativos com `npm ci && npm run build` (ou deixe esse comando configurado como build command do Cloud).
4. Rode `php artisan optimize` se este já for o padrão do ambiente e verifique login, `/estatisticas`, um perfil de jogador e uma página pública de partida.
5. Após o deploy, confira uma imagem Open Graph em `/p/partidas/{token}/imagem.png` e teste o preview do link em um mensageiro. Não compartilhe tokens de produção em canais públicos.

Não há necessidade de job ou worker adicional para estes recursos; o PNG do placar é gerado sob demanda e usa GD quando disponível, com fallback para a imagem da marca.
