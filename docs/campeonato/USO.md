# Guia de Uso — Módulo de Campeonatos (`/campeonatos`)

Este documento descreve em detalhes o funcionamento e o fluxo operacional do módulo de **Campeonatos** do aplicativo **Hockey Game**.

---

## 1. Visão Geral e Isolamento de Dados

O módulo de **Campeonatos** permite que a organização (conta autenticada) gerencie torneios municipais ou copas com equipes externas inscritas, fase de grupos classificatória, chaveamento eliminatório (mata-mata), súmula ao vivo com cronômetro e resumo oficial com pódio e estatísticas.

- **Isolamento em relação à pelada**: todas as informações de campeonatos ficam armazenadas em 6 tabelas próprias (`championships`, `championship_teams`, `championship_players`, `championship_games`, `championship_game_events` e `championship_audit_logs`). Os jogadores inscritos em equipes de campeonato **não** aparecem em `/jogadores` nem no `/sorteio` da pelada, e os gols de campeonato **não** interferem em `/partidas` ou `/estatisticas`.
- **Isolamento por conta (Multi-tenancy)**: cada conta enxerga e opera exclusivamente seus próprios campeonatos (`BelongsToTeam` + `scopeBindings()` nas rotas).

---

## 2. Passo a Passo do Fluxo do Campeonato

O ciclo de vida completo segue a sequência:

```text
Criar campeonato (Rascunho)
  → Inscrever times e elencos
  → Ajustar regras ao regulamento (editáveis antes do sorteio)
  → Sortear grupos e gerar agenda de jogos (Fase de grupos — trava regras e inscrições de times)
  → Realizar jogos da fase de grupos (Placar ao vivo e classificação automática)
  → Gerar chave e realizar jogos do mata-mata (Mata-mata — avanço automático até Final / 3º lugar)
  → Consultar e imprimir Resumo e Pódio (Encerrado)
```

### 2.1 Criar Campeonato
1. Acesse **Campeonatos** (`/campeonatos`) no menu principal e clique em **Novo campeonato**.
2. Preencha:
   - **Nome do campeonato** (obrigatório);
   - **Cidade** (obrigatório);
   - **Local / Ginásio padrão** (opcional, usado como sugestão inicial nos jogos gerados);
   - **Data de início** e **Data de término** (opcionais).
3. O campeonato nasce com status **`Rascunho` (`draft`)**.

### 2.2 Times e Elencos (Aba _Times e elencos_)
1. Enquanto o campeonato está em **`Rascunho`**, clique em **Inscrever equipe** para cadastrar cada time participante:
   - **Nome da equipe** (único dentro do campeonato) e **Sigla** opcional;
   - **Cor principal** e **Cor secundária** (usadas nos escudos gerados por iniciais e nos botões do placar ao vivo);
   - **Escudo da equipe**: envio opcional de imagem raster (`JPG`, `JPEG`, `PNG` ou `WebP`, até 4 MB). Arquivos `SVG` são bloqueados por segurança;
   - **Pote de sorteio** (opcional, ex.: `1` para cabeças de chave): equipes do mesmo pote são distribuídas em grupos separados durante o sorteio.
2. Em cada equipe, clique em **+ Jogador** para cadastrar os atletas do elenco:
   - **Nome do jogador**;
   - **Número da camisa** (`0` a `999`, único dentro daquela equipe — equipes diferentes podem ter camisas de mesmo número);
   - **Posição** (`Universal`, `Atacante`, `Defensor` ou `Goleiro`) e marcação de **Goleiro**.
3. **Nota sobre bloqueio após o sorteio**: após sortear os grupos, não é mais permitido adicionar ou remover equipes. O cadastro de novos jogadores nos elencos existentes continua liberado até o encerramento do campeonato (e a remoção de um jogador só é permitida se ele não tiver gols, assistências ou cobranças registrados em súmula).

### 2.3 Regras do Campeonato (Aba _Regras_)
Os valores exibidos ao criar um campeonato são **padrões sugeridos** e podem ser livremente editados na aba **Regras** enquanto o campeonato estiver em **`Rascunho`**:

- **Fase de grupos e vagas**:
  - **Quantidade de grupos** (`1` a `8`, padrão `2`);
  - **Classificados por grupo** (`1` a `8`, padrão `2`);
  - **Melhores classificados extras** (`0` a `8`, padrão `0` — ex.: melhores 3ºs colocados no geral);
  - **Turno e returno** (desmarcado por padrão = turno único dentro de cada grupo; quando marcado, gera 2º turno invertendo o mando).
  - *Regra de validação*: o total de classificados para o mata-mata deve ficar entre `2` e `16` equipes, e o campeonato precisa ter pelo menos `grupos × 2` equipes inscritas.
- **Sistema de pontuação**:
  - Pontos por **Vitória** (padrão `3`), **Empate** (padrão `1`) e **Derrota** (padrão `0`);
  - Opção de **exigir desempate por prorrogação/shootout na fase de grupos**, configurando os pontos de vitória e derrota em prorrogação/shootout (padrão `2` e `1`).
- **Ordem dos critérios de desempate**:
  - Reordene livremente a cadeia de critérios: `Pontos (P)` → `Confronto direto (mini-tabela apenas entre as equipes empatadas)` → `Saldo de gols (SG)` → `Gols pró (GP)` → `Número de vitórias (V)` → `Menos gols contra (GC)` → `Sorteio / definição manual auditada`.
- **Tempo de jogo e cronômetro**:
  - **Minutos por período** (padrão `10`), **Número de períodos** (padrão `2`), **Intervalo / descanso em segundos** (padrão `120`), **Contagem regressiva em segundos** (padrão `5`) e **Minutos da prorrogação** (padrão `5`).
- **Mata-mata e súmula**:
  - **Resolução de empate no mata-mata**: `Prorrogação seguida de shootout / pênaltis` (padrão) ou `Direto para shootout / pênaltis`;
  - **Disputa de 3º lugar**: habilitada por padrão entre os perdedores das semifinais;
  - **Máximo de assistências por gol**: `0` (sem assistências), `1` ou `2` (padrão `2`).

> **Importante**: assim que o sorteio dos grupos é confirmado, **as regras ficam travadas** para preservar a integridade competitiva do torneio.

### 2.4 Sorteio de Grupos e Classificação (Aba _Grupos e jogos_)
1. Na aba **Grupos e jogos**, clique em **Sortear grupos e gerar jogos** e confirme.
2. O servidor realiza o sorteio persistente (respeitando potes, se configurados), cria todos os confrontos da fase de grupos (método do círculo / _round-robin_) e muda o status do campeonato para **`Fase de grupos` (`group_stage`)**.
3. **Refazer sorteio**: caso nenhum jogo tenha sido iniciado, o botão **Refazer sorteio** permite realizar um novo sorteio mediante confirmação e preenchimento obrigatório do **motivo**, registrando a ação na auditoria (`groups_redrawn`).
4. **Classificação automática**: as tabelas (`P, J, V, E, D, GP, GC, SG`) são calculadas automaticamente a partir das partidas **encerradas** de cada grupo.
5. **Desempate manual auditado**: se duas ou mais equipes terminarem empatadas em todos os critérios objetivos configurados, o sistema exibe um alerta de **Empate técnico** no grupo com o botão **Resolver empate manual**. O organizador define a ordem das equipes empatadas e informa o **motivo** (ex.: sorteio em reunião técnica), que fica gravado na auditoria (`tie_break_resolved`).

### 2.5 Placar ao Vivo e Súmula (`/campeonatos/{id}/jogos/{jogo}`)
1. Nos cartões de jogos (na aba de grupos ou na aba de mata-mata), é possível clicar em **Agendar** para definir o local e a data/hora da partida, ou clicar em **Placar ao vivo / Súmula** para abrir a tela da partida.
2. Na tela do jogo (`GameLive.vue`):
   - **Cronômetro e Áudio**: reutiliza o motor de cronômetro por timestamps (`useMatchClock`), sons de apito/buzzer/contagem (`useMatchAudio`), Wake Lock (`useWakeLock`) e modo **Tela cheia**. O estado do cronômetro em andamento é salvo localmente no navegador na chave `campeonato-relogio:{gameId}`, permitindo recarregar a página sem perder a contagem do período.
   - **Iniciar jogo**: quando a partida está `Agendada`, clique em **Iniciar jogo** e confirme para mudá-la para `Ao vivo`.
   - **Registrar Gol (`+ GOL`)**:
     1. Clique no botão grande **`+ GOL`** da equipe da casa ou visitante;
     2. Escolha o **autor do gol** na lista de jogadores inscritos daquela equipe (`#camisa + nome`);
     3. Escolha até `N` assistências (conforme `max_assists_per_goal` das regras) dentre os demais atletas da mesma equipe, ou selecione **Sem assistência** / **Sem 2ª assistência**.
     4. O gol é gravado imediatamente no servidor com período, tempo decorrido, tempo restante e indicador de prorrogação.
   - **Prorrogação**: quando as regras permitem prorrogação em caso de empate, o botão **Prorrogação** ajusta o cronômetro para o tempo extra configurado e marca a opção de gol na prorrogação.
   - **Disputa de Shootout / Pênaltis**: exibida em painel separado quando permitido pelas regras e com placar regular empatado. Registre cada cobrança informando equipe, cobrador, rodada e se foi **Convertido (`Sim` / `Não`)**.
     - *Regra fundamental*: cobranças convertidas no shootout atualizam exclusivamente o placar de shootout (`home_shootout_score × away_shootout_score`) para definir o vencedor do confronto, **nunca** somando no placar regular nem nas estatísticas de artilharia dos atletas.
   - **Remover evento da súmula**: enquanto a partida está `Ao vivo` (ou reaberta), qualquer gol ou cobrança pode ser anulado clicando em **Remover**, informando o **motivo** e confirmando.
   - **Finalizar partida**: clique em **Finalizar** e confirme. Se o jogo exigir vencedor (ex.: mata-mata empatado sem definição no shootout), o servidor impede o encerramento e exibe a mensagem de validação correspondente.

### 2.6 Chaveamento do Mata-Mata (Aba _Mata-mata_)
1. Quando todas as partidas da fase de grupos estiverem encerradas e não houver empates técnicos pendentes na linha de classificação, clique em **Gerar chave do mata-mata** na aba **Mata-mata**.
2. O sistema dimensiona a chave para a próxima potência de 2 (`2, 4, 8` ou `16`), aplica o cruzamento olímpico (`1º A × 2º B`, etc.) evitando confrontos do mesmo grupo na primeira fase quando possível, e concede avanços automáticos (**`BYE`**) aos melhores cabeças de chave quando o número de classificados não fecha potência de 2.
3. Conforme cada confronto eliminatório é finalizado:
   - O **vencedor** avança automaticamente para o slot destino (`winner:QF1`, `winner:SF1`, etc.);
   - Nas semifinais, se a **disputa de 3º lugar** estiver habilitada nas regras, os **perdedores** avançam automaticamente para o jogo de 3º lugar (`loser:SF1 × loser:SF2`).
4. Ao finalizar a **Grande Final** (e a disputa de 3º lugar, quando habilitada), o campeonato passa automaticamente para o status **`Encerrado` (`completed`)**.

### 2.7 Resumo Oficial e Pódio (`/campeonatos/{id}/resumo`)
Acessível pelo botão **Resumo e pódio** na listagem ou no cabeçalho do campeonato:
- **Pódio oficial**: exibe **1º Lugar (Campeão)**, **2º Lugar (Vice-campeão)** e **3º Lugar** somente quando já definidos pelas partidas finais concluídas; enquanto não definidos, exibe explicitamente **`A definir`**.
- **Totais do campeonato**: quantidade de jogos finalizados sobre o total programado, total de gols marcados (tempo normal + prorrogação, excluindo shootout) e média de gols por partida.
- **Estatísticas individuais**:
  - **Artilharia (Gols)**;
  - **Líderes em Assistências** (contabilizando tanto 1ª quanto 2ª assistências);
  - **Pontos / Participações em Gols (`G + A`)**.
- **Tabelas de classificação dos grupos**, **Chaveamento do mata-mata** e **Resultados das partidas finalizadas**.
- Possui botão **Imprimir / PDF** com formatação limpa para impressão.

---

## 3. Reabertura de Partidas, Retificação e Auditoria

Para corrigir erros de súmula após o encerramento de um jogo:
1. Abra a súmula da partida encerrada (`/campeonatos/{id}/jogos/{jogo}`) e clique em **Reabrir partida para retificação**.
2. Informe obrigatoriamente o **motivo** da reabertura:
   - **Jogo da fase de grupos**:
     - Se algum jogo do mata-mata já tiver sido iniciado, a reabertura é **bloqueada** para proteger a fase seguinte.
     - Se a chave do mata-mata já tiver sido gerada mas nenhum jogo eliminatório começou, marque a opção **Reiniciar chave do mata-mata** para confirmar a remoção dos slots não iniciados e reabrir o jogo de grupo.
   - **Jogo do mata-mata**:
     - Se o jogo dependente da fase seguinte (ex.: a Final que recebe o vencedor da Semifinal) já tiver sido iniciado, a reabertura da fase anterior é **bloqueada**.
     - Se a fase seguinte ainda estiver `Agendada`, reabrir o jogo anterior limpa automaticamente a equipe avançada no slot seguinte até que a partida reaberta seja novamente finalizada.
3. Faça as correções necessárias (adicionar gols/cobranças ou remover eventos com justificativa) e clique novamente em **Finalizar**.
4. Na aba **Histórico** do campeonato, todos os eventos sensíveis (`groups_drawn`, `groups_redrawn`, `tie_break_resolved`, `bracket_generated`, `bracket_reset`, `game_finished`, `game_reopened`, `event_removed`) ficam registrados em ordem cronológica com usuário responsável, data/hora, motivo e snapshots JSON do estado anterior e posterior.

---

## 4. Notas de Deploy e Migrações

Ao atualizar uma instalação existente do **Hockey Game** com o módulo de Campeonatos:

1. **Banco de dados (6 novas migrações)**:
   - As migrações criam exclusivamente as 6 novas tabelas do módulo (`championships`, `championship_teams`, `championship_players`, `championship_games`, `championship_game_events` e `championship_audit_logs`), sem modificar tabelas pré-existentes.
   - Execute:
     ```bash
     php artisan migrate --force
     ```
2. **Armazenamento público para escudos**:
   - Os escudos enviados no cadastro das equipes são gravados no disco `public` (`storage/app/public/championships/{user_id}/logos`). Certifique-se de que o link simbólico exista:
     ```bash
     php artisan storage:link
     ```
3. **Build do frontend**:
   - Compile os novos componentes e páginas Vue/Inertia:
     ```bash
     npm run build
     ```
