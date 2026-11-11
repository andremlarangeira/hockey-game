# Na Quadra

Aplicativo para organizar times de hockey informal: elenco e presença, sorteio equilibrado, cronômetro, gols e histórico.

## O que está incluído

- Cadastro e login: cada conta é um time e só acessa seus próprios jogadores, partidas e estatísticas.
- Identidade do time: nome, escudo e cores principais com contraste de texto.
- Jogadores: foto, número único por time, nível de 1 a 5 estrelas em incrementos de meia estrela e presença em lote.
- Sorteio aleatório equilibrado por estrelas, por **jogadores por equipe** (padrão) ou por **número de equipes**. Reservas e ajustes por arrastar e soltar, com suporte a toque. No modo número de equipes, todos os presentes são distribuídos e cada equipe precisa ter pelo menos dois jogadores. No outro modo, somente os jogadores excedentes ficam nos reservas.
- Partida: duração e quantidade de tempos, descanso, contagem inicial, pausa, retomada, reinício e transições automáticas. O relógio usa timestamps, não apenas intervalos.
- Cards compactos de jogadores com checkbox de presença, foto e número da camisa em destaque. As estrelas aparecem somente no cadastro/edição e continuam sendo usadas no equilíbrio.
- Botões grandes de gol: primeiro o autor, depois a assistência ou “Sem assistência”. Ao confirmar, o cronômetro é pausado e reinicia com a contagem configurada; o tempo jogado é preservado. Desfazer apaga o último gol e deixa um evento na cronologia, sem retroceder o cronômetro.
- Bipes nos últimos 5 segundos de cada contagem, gravação real de apito de árbitro no início (e após a contagem de gol) e buzzer grave no fim. Opção de áudio local para esses eventos e botões de teste.
- Tela cheia, tentativa de travar orientação em paisagem e Wake Lock durante a partida.
- Salvamento dos elencos, resultado, gols, assistências, períodos, cronômetro e cronologia. Recuperação do rascunho no mesmo navegador após F5/fechamento acidental.
- Histórico de partidas e estatísticas de jogos, gols e assistências.
- Campeonatos municipais e torneios completos (`/campeonatos`), isolados das partidas e estatísticas da pelada.
- Interface e mensagens em português do Brasil, com CI configurado no GitHub Actions.

## Campeonatos

O módulo de **Campeonatos** (`/campeonatos`) permite organizar torneios municipais ou ligas completas com equipes convidadas dentro da mesma conta, sem misturar atletas, jogos ou gols com o elenco e as estatísticas da pelada (`/jogadores`, `/partidas` e `/estatisticas` permanecem exclusivos da pelada).

### Fluxo de operação

1. **Criar campeonato**: defina nome, cidade, ginásio/local padrão e datas de início e término (status inicial: `Rascunho`).
2. **Times e elencos**: inscreva as equipes participantes com nome, sigla, cores, pote de sorteio opcional e escudo (JPG, PNG ou WebP até 4 MB; SVG é recusado por segurança). Cadastre o elenco de cada equipe com nome, número de camisa (único dentro da própria equipe), posição e indicação de goleiro.
3. **Regras (padrões editáveis antes do sorteio)**: todos os valores vêm preenchidos apenas como sugestão inicial e podem ser ajustados livremente ao regulamento local enquanto o campeonato estiver em `Rascunho`:
   - Quantidade de grupos, classificados por grupo, melhores classificados extras e turno único ou turno e returno;
   - Pontuação por vitória/empate/derrota (ex.: 3/1/0) e opção de exigir desempate por prorrogação/shootout já na fase de grupos (com pontuação própria, ex.: 2/1);
   - Ordem configurável dos critérios de desempate (pontos, confronto direto em mini-tabela entre os empatados, saldo de gols, gols pró, vitórias, menos gols contra e sorteio manual auditado);
   - Tempo de jogo (minutos por período, número de períodos, intervalo, contagem regressiva e duração da prorrogação);
   - Resolução de empate no mata-mata (`Prorrogação + shootout` ou `Direto para shootout`), disputa de 3º lugar e máximo de assistências por gol (`0`, `1` ou `2`).
4. **Sorteio de grupos**: distribui as equipes nos grupos no servidor (respeitando potes, quando usados), grava o resultado no banco (recarregar a página nunca altera os grupos), gera todos os jogos da fase de grupos e **trava as regras e a inscrição/remoção de equipes**. Caso nenhum jogo tenha começado, é possível refazer o sorteio mediante confirmação e justificativa auditada.
5. **Jogos e placar ao vivo**: cada partida possui agendamento de local/horário e tela de placar ao vivo (`/campeonatos/{id}/jogos/{jogo}`) que reutiliza o cronômetro, os alertas sonoros, a tela cheia e o Wake Lock do aplicativo (persistindo o estado do relógio no navegador em `campeonato-relogio:{gameId}`). Os eventos são salvos incrementalmente no servidor:
   - **Iniciar jogo**: muda o status de `Agendado` para `Ao vivo`;
   - **Gols e assistências**: seleção do autor no elenco da equipe e de até `N` assistências (conforme regra configurada) ou “Sem assistência”, com suporte a prorrogação;
   - **Shootout / Pênaltis**: painel separado para registrar cobranças (cobrador, convertido sim/não e rodada). Cobranças de shootout definem o vencedor em caso de empate, mas **nunca contam como gol no placar regular nem na artilharia**;
   - **Finalizar partida**: valida se o confronto exige vencedor (no mata-mata ou conforme regra de grupo), encerra a súmula e atualiza imediatamente a classificação derivada ou avança as equipes no chaveamento.
6. **Mata-mata**: após encerrar todos os jogos dos grupos (e resolver eventuais empates técnicos na linha de corte com sorteio manual justificado), gera a chave eliminatória em potência de 2 (com avanço automático de `BYE` para os melhores cabeças de chave quando necessário, cruzamento olímpico `1A × 2B`, disputa de 3º lugar opcional entre os perdedores das semifinais e Grande Final).
7. **Resumo e pódio**: página dedicada (`/campeonatos/{id}/resumo`), responsiva e formatada para impressão/PDF, com pódio oficial (1º, 2º e 3º lugares exibidos apenas quando definidos; caso contrário, `A definir`), totais do torneio (jogos finalizados e gols excluindo shootout), tabelas de artilharia, assistências e pontos (`G + A`), classificação dos grupos, chaveamento e lista de resultados.

### Reabertura, retificação e auditoria

- **Proteção de fases dependentes**: reabrir uma partida encerrada exige informar o **motivo**. Jogos da fase de grupos não podem ser reabertos se alguma partida do mata-mata já tiver começado (se a chave foi apenas gerada e nenhum jogo começou, o sistema solicita confirmação para reiniciar a chave não iniciada). Jogos do mata-mata não podem ser reabertos se a fase seguinte dependente já tiver começado.
- **Retificação sem exclusão silenciosa**: remover um gol ou cobrança de shootout realiza _soft delete_ do evento, recalcula o placar da partida na mesma transação e grava um registro imutável em `championship_audit_logs` com autor, data/hora, motivo e snapshots do estado antes e depois.
- Para mais detalhes de uso, consulte [`docs/campeonato/USO.md`](docs/campeonato/USO.md).

## Instalação local

Requer PHP 8.3+ (testado com PHP 8.4), Composer, Node.js 22+ e extensões usuais do Laravel: SQLite/PDO, BCMath, Mbstring, OpenSSL, XML e Zip.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan storage:link
npm ci
npm run build
php artisan serve
```

Abra `http://127.0.0.1:8000` e use a conta demonstrativa:

- E-mail: `demo@naquadra.test`
- Senha: `hockey123`

Em produção, rode `php artisan migrate --force` sem `--seed` ou troque a senha do usuário demo antes de dar acesso.

### MySQL

O arquivo `.env.example` usa SQLite. Para MySQL, use `DB_CONNECTION=mysql` e preencha `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD` no `.env` (não o edite no repositório). Depois rode as migrações.

## Deploy em hospedagem PHP

Aponte o document root para `public/`, use HTTPS, configure `APP_ENV=production` e `APP_DEBUG=false` no servidor e deixe `storage/` e `bootstrap/cache/` graváveis pelo usuário do PHP. Nunca envie `.env` ao repositório.

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate       # somente na primeira instalação
php artisan migrate --force
php artisan storage:link
npm ci && npm run build
php artisan optimize
```

As funcionalidades deste aplicativo rodam de forma síncrona, sem tarefas agendadas; por isso, não é necessário manter fila ou worker. O cache usa o banco por padrão.

> **Nota de atualização / deploy do módulo de Campeonatos**:
> - O módulo adiciona **6 novas migrações exclusivamente de criação de tabelas** (`championships`, `championship_teams`, `championship_players`, `championship_games`, `championship_game_events` e `championship_audit_logs`), sem alterar nenhuma tabela existente: execute `php artisan migrate --force`.
> - O comando `php artisan storage:link` é necessário para servir os escudos enviados para as equipes inscritas (armazenados no disco `public` em `storage/app/public/championships/{user_id}/logos`).
> - Execute `npm run build` para compilar as novas páginas Vue/Inertia do módulo de campeonatos.

## Verificações

```bash
./vendor/bin/pest
npm run types:check
npm run build
npm run test:clock
```

O smoke test de navegador exige o app servido com o seed de demonstração e Chrome/Chromium. Use `CHROME_PATH` se o navegador estiver em outro local.

```bash
npm run test:e2e
```

`APP_URL` e `SCREENSHOT_DIR` podem parametrizar o teste. Ele gera capturas full-page e a `02-sorteio-viewport.png` com os elementos sticky/fixed na posição real do viewport. Ele faz login real no demo, sorteia, controla o relógio, registra gol e assistência, recarrega a partida e salva o histórico. Por isso, o banco demo receberá uma partida a cada execução; não rode contra um banco de produção.

## Limites dos navegadores e privacidade

- Áudio, Fullscreen, travar orientação e Wake Lock dependem do navegador/sistema. O contexto de áudio é liberado ao iniciar a partida ou abrir o modal de gol. No iPhone/Safari, após recarregar, use o botão de pausa/continuar para habilitar os bipes novamente se o navegador bloquear a retomada automática. O aplicativo funciona sem travar orientação.
- Sons personalizados são arquivos locais no navegador e ficam como URLs temporárias (`blob:`). Não são enviados ao servidor nem às partidas salvas.
- O rascunho em andamento fica no armazenamento deste navegador, por conta. Não é sincronização online nem backup multi-dispositivo. Limpar os dados do navegador descarta esse rascunho; partidas salvas ficam no banco.
- Recuperar um rascunho não toca os sons de transições ocorridas enquanto a página estava fechada; o cronômetro ainda acerta o tempo e o histórico registra os limites pelo horário planejado.
- O toque curto em itens do sorteio não move jogadores: é preciso arrastá-los para outra lista. Um jogador pode ser enviado para reservas manualmente mesmo no modo número de equipes.

## Créditos e licença de áudio

- `public/sons/apito-inicio.wav`: gravação real de apito de árbitro em ginásio (_Referee's whistle blowing inside a gymnasium_), gravada por **SpliceSound**, obtida do [Wikimedia Commons — File:218318 splicesound referee-whistle-blow-gymnasium.wav](https://commons.wikimedia.org/wiki/File:218318_splicesound_referee-whistle-blow-gymnasium.wav) (origem no [Freesound #218318](https://freesound.org/people/SpliceSound/sounds/218318/)), sob licença **CC0 1.0 Universal (Domínio Público)** — [creativecommons.org/publicdomain/zero/1.0/](https://creativecommons.org/publicdomain/zero/1.0/deed.pt_BR). Convertida para WAV mono 22.050 Hz de 1,0 s normalizado.
