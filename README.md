# Na Quadra

Aplicativo para organizar times de hockey informal: elenco e presença, sorteio equilibrado, cronômetro, gols e histórico.

## O que está incluído

- Cadastro e login: cada conta é um time e só acessa seus próprios jogadores, partidas e estatísticas.
- Identidade do time: nome, escudo e cores principais com contraste de texto.
- Jogadores: foto, número único por time, nível de 1 a 5 estrelas em incrementos de meia estrela e presença em lote.
- Sorteio aleatório equilibrado por estrelas, por **jogadores por equipe** (padrão) ou por **número de equipes**. Reservas e ajustes por arrastar e soltar, com suporte a toque. No modo número de equipes, todos os presentes são distribuídos e cada equipe precisa ter pelo menos dois jogadores. No outro modo, somente os jogadores excedentes ficam nos reservas.
- Partida: duração e quantidade de tempos, descanso, contagem inicial, pausa, retomada, reinício e transições automáticas. O relógio usa timestamps, não apenas intervalos.
- Botões grandes de gol: primeiro o autor, depois a assistência ou “Sem assistência”. Desfazer apaga o último gol e deixa um evento na cronologia.
- Sons já incluídos, opção de áudio local para cada evento e botões de teste.
- Tela cheia, tentativa de travar orientação em paisagem e Wake Lock durante a partida.
- Salvamento dos elencos, resultado, gols, assistências, períodos, cronômetro e cronologia. Recuperação do rascunho no mesmo navegador após F5/fechamento acidental.
- Histórico de partidas e estatísticas de jogos, gols e assistências.
- Interface e mensagens em português do Brasil, com CI configurado no GitHub Actions.

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

- Áudio, Fullscreen, travar orientação e Wake Lock dependem do navegador/sistema. O áudio é liberado pela primeira interação; o iPhone não oferece todas essas APIs. O aplicativo funciona sem travar orientação.
- Sons personalizados são arquivos locais no navegador e ficam como URLs temporárias (`blob:`). Não são enviados ao servidor nem às partidas salvas.
- O rascunho em andamento fica no armazenamento deste navegador, por conta. Não é sincronização online nem backup multi-dispositivo. Limpar os dados do navegador descarta esse rascunho; partidas salvas ficam no banco.
- Recuperar um rascunho não toca os sons de transições ocorridas enquanto a página estava fechada; o cronômetro ainda acerta o tempo e o histórico registra os limites pelo horário planejado.
- O toque curto em itens do sorteio não move jogadores: é preciso arrastá-los para outra lista. Um jogador pode ser enviado para reservas manualmente mesmo no modo número de equipes.
