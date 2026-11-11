# Campeonato — arquitetura sintetizada (contrato de implementação)

Este documento é o contrato. O desenho base completo (tipos, assinaturas, pseudocódigo) está em
`desenho-base-candidato-1.md`; onde este arquivo diverge dele, **este arquivo vence**.
Grounding do sistema existente: `grounding.md`.

## Problema

Organizar um campeonato municipal de hóquei dentro do app Na Quadra (cada conta = uma organização/tenant):
inscrição de times externos (nome, escudo, elenco com número/posição/goleiro), regras configuráveis antes do
sorteio, sorteio persistente de grupos, turno (e opcionalmente returno) por grupo, classificação automática,
desempates bem definidos, mata-mata gerado da classificação, placar ao vivo com gols/assistências/períodos
reaproveitando o relógio existente, finalizar/reabrir/retificar com proteção de fases dependentes e auditoria,
e um resumo com estatísticas e pódio. Restrições do sistema atual: `players`/`matches`/`goals` pertencem ao fluxo
de pelada (sorteio equilibrado, `StoreMatchRequest`, `/estatisticas` agrega TODOS os `goals` da conta, `players`
tem número de camisa único por conta). O fluxo de pelada e o placar em tela cheia não podem regredir.

## Uso (visão de quem chama)

```php
// Controller fino: FormRequest -> TournamentEngine -> redirect/Inertia
public function draw(DrawGroupsRequest $request, Championship $championship, TournamentEngine $engine)
{
    $engine->drawGroups($championship, actor: $request->user(), redraw: $request->boolean('refazer'), reason: $request->validated('motivo'));
    return back()->with('success', 'Grupos sorteados e jogos gerados.');
}

// Teste de domínio
$engine->recordGoal($game, new GoalCommand(side: TeamSide::Home, scorerId: $a->id, assistIds: [$b->id, $c->id], period: 1, elapsedSeconds: 312, remainingSeconds: 288, overtime: false));
$engine->finishGame($game, $actor);
expect($engine->standings($championship)['A']->rows[0]->points)->toBe(3);

// Vue
defineProps<{ championship: ChampionshipProp; standings: Record<string, GroupStandingsProp>; bracket: ChampionshipGameProp[]; summary: ChampionshipSummaryProp }>()
```

## Forma

- Módulo autocontido `App\Championships` (enums, DTOs, `StandingsCalculator`, `BracketPlanner`, `TournamentEngine`)
  e 6 tabelas próprias: `championships`, `championship_teams`, `championship_players`, `championship_games`,
  `championship_game_events`, `championship_audit_logs`. Tabelas/arquivos da pelada ficam intocados
  (per boundary-discipline / split ownership).
- **Uma porta de escrita**: todo estado de campeonato muda só via `TournamentEngine` (dono único do estado). Controllers
  nunca escrevem modelos de campeonato diretamente (exceto leituras). Cada transição roda em `DB::transaction` com
  `lockForUpdate()` na linha do campeonato (enxerto do candidato 2).
- **Derivado vs armazenado**: classificação, artilharia/assistências/pontos, pódio = derivados na leitura de jogos
  finalizados e eventos não excluídos. Placar do jogo (`home_regular_score`, `*_shootout_score`) é materializado a partir
  dos eventos, recalculado pelo engine na mesma transação de cada evento. Topologia da chave é armazenada (slots com
  `slot_key` único e `home_source`/`away_source` = `group:A:1`, `best:1`, `winner:SF1`, `loser:SF1`, `bye`).
- **Status do campeonato** (`ChampionshipStatus`): `draft` (inscrições e regras editáveis) → `group_stage` (após sorteio;
  regras TRAVADAS; times não podem ser inscritos/removidos; elenco pode receber jogadores) → `knockout_stage` → `completed`
  (final e, se habilitada, disputa de 3º finalizadas). Voltar de fase só por ação explícita auditada (refazer sorteio,
  reabrir jogo com reset da chave não iniciada).
- **Regras** (`ChampionshipRules`, JSON com cast, defaults editáveis e explicitados na UI como "padrão sugerido, ajuste ao
  regulamento"): grupos, classificados por grupo, melhores extras (opcional, 0 padrão), turno/returno, pontos V/E/D
  (3/1/0), modo de empate na fase de grupos (`allow_draw` padrão | `overtime_shootout` com pontos de vitória/derrota na
  prorrogação/shootout 2/1), ordem de desempate (padrão: pontos, confronto direto, saldo, gols pró, vitórias, menos gols
  contra, sorteio manual), minutos por período / nº de períodos / descanso / contagem, minutos de prorrogação, resolução
  de empate no mata-mata (`overtime_then_shootout` padrão | `shootout_only`), disputa de 3º (sim padrão), máximo de
  assistências por gol (0..2, padrão 2). Nada disso é "regra oficial" codificada.
- **Sorteio**: aleatório no servidor (com potes opcionais via `seed_pot`), persistido em `group_code`/`group_draw_order`;
  refresh não muda nada. Refazer: só com `refazer=true` + motivo, e só se nenhum jogo de grupo começou (sem eventos e
  `scheduled`); apaga jogos de grupo não iniciados e audita (`groups_redrawn`). Validação: nº de times ≥ grupos×2,
  classificados por grupo < tamanho do menor grupo, total de classificados entre 2 e 16.
- **Jogos de grupo**: round-robin por grupo (método do círculo), `slot_key` determinístico com ids ordenados
  (`G-A-L1-<min>-<max>`) — impossível duplicar par, inclusive invertido. Returno usa `L2` com mando invertido.
- **Classificação**: P J V E D GP GC SG; refinamento de "baldes" pela ordem de critérios; confronto direto = mini-tabela
  só entre os empatados, reaplicada aos sub-baldes. Balde ainda empatado após todos os critérios → `unresolved`
  (sinalizado na UI e bloqueia gerar o mata-mata se cruzar a linha de classificação). Resolução manual: ordem explícita
  dos times + motivo, gravada em `manual_tie_resolutions` e em `championship_audit_logs` (quem/quando/por quê); só é
  usada se exatamente aqueles times continuarem empatados.
- **Mata-mata**: tamanho = próxima potência de 2 (2..16); seeds ordenados por colocação no grupo e depois campanha;
  byes para os melhores seeds (slot `is_bye`, avanço automático); cruzamento padrão (1A×2B, 1B×2A, etc.) evitando
  mesmo grupo na 1ª rodada quando possível; `3RD` com `loser:SF1`/`loser:SF2` se habilitado; `FINAL`. Gerar de novo
  é idempotente enquanto nenhum jogo do mata-mata começou (upsert por `slot_key`).
- **Eventos**: `goal` (autor + até N assistências do MESMO time, distintas, ≠ autor, jogadores inscritos naquele time;
  flag `is_overtime`), `shootout_attempt` (cobrador do time, convertido sim/não, rodada; NUNCA conta como gol em
  estatísticas nem no placar regular), `period_mark`. Desfazer/retificar = soft delete + auditoria `event_removed`;
  nada é apagado silenciosamente. Eventos só em jogo `live` (iniciar jogo = `scheduled`→`live`), exceto retificação de jogo
  reaberto.
- **Finalizar**: idempotente; jogo de mata-mata exige vencedor (placar regular ou shootout); jogo de grupo empatado só
  é aceito em `allow_draw` (em `overtime_shootout` exige vencedor). Avanço preenche o slot destino. Final (+3º) finalizados
  → `completed`.
- **Reabrir** (motivo obrigatório, auditado com snapshot antes/depois): jogo de grupo bloqueado se algum jogo do mata-mata
  começou; se a chave existe mas nada começou, exige `resetar_chave=true` (apaga slots não iniciados, volta a
  `group_stage`, audita `bracket_reset`). Jogo de mata-mata bloqueado se o jogo dependente (`winner:`/`loser:` dele)
  começou; senão limpa o time do slot dependente. Campeonato `completed` volta ao status anterior ao reabrir a final/3º.
- **Escudos**: `image|mimes:jpg,jpeg,png,webp|max:4096` (SVG recusado por segurança: arquivo enviado por terceiros e
  servido do disco público), nome aleatório em `championships/{user_id}/logos`, arquivo antigo removido na troca.
- **Tenancy**: todos os modelos usam `BelongsToTeam`; rotas aninhadas com `scopeBindings()`; o engine valida que ids
  de jogadores pertencem ao time do lado indicado naquele jogo. Sem link público (o app não tem esse padrão); o resumo é
  uma página autenticada. Link público fica como opt-in futuro.
- **Frontend**: páginas `championships/Index`, `Show` (abas: Times e elencos, Regras, Grupos e jogos, Mata-mata,
  Resumo, Histórico/auditoria), `GameLive` (reusa `useMatchClock`, `useMatchAudio`, `useWakeLock`, `formatClock` sem
  alterar `matches/Create.vue` nem o core do relógio; eventos enviados ao servidor um a um; estado do relógio em
  `localStorage` `campeonato-relogio:{gameId}`), `Summary` (resumo com pódio). Item de navegação "Campeonatos".

## Decisão de síntese

- Base: **candidato 1 (motor autocontido)**. Rubrica: (1) isolamento/sem regressão da pelada — C1 não toca
  `matches`/`goals`/`Create.vue`/`StoreMatchRequest`; C2 estende essas tabelas, o formulário de 990 linhas e o FormRequest
  e obriga filtro `casual()` em toda consulta existente (vazamento por esquecimento). (2) fonte única — C1 deriva a
  classificação; C2 persiste snapshot que precisa ser re-sincronizado a cada reabertura. (3) profundidade de interface —
  empate (ambos uma fachada). (4) invariantes no banco — C1 (`slot_key` único, camisa única por time inscrito, sem colidir
  com `UNIQUE(user_id, jersey_number)` de `players`). (5) entregável em unidades — C1 mais linear.
- Enxertos do candidato 2: `lockForUpdate()` na linha do campeonato em toda transição; fase explícita em que as regras
  ficam travadas após o sorteio; snapshot antes/depois na auditoria de reabertura; confirmação explícita do avanço.
- Rejeitado do C2: `next_match_id` em FK (duas formas de expressar a mesma ligação junto com `slot_key`); modo "fixture"
  dentro de `matches/Create.vue`; tabela `championship_standings` persistida.
- Ajustes ao C1: shootout com `period` nulo (sem "99"); `slot_key` de grupo com ids ordenados; regras travadas após
  sorteio; SVG recusado; jogo precisa estar `live` para receber eventos.
- Limitação de processo: o pstack recomenda runners e juiz cruzado de famílias de modelo distintas; por preferência
  do dono, os dois desenhos foram gerados pelo agy com `gemini-4-argon-high` em direções estruturais atribuídas, e não
  houve juiz cruzado de outra família — o orquestrador pontuou a rubrica.

## Tradeoffs aceitos

- Aceitamos 6 tabelas novas e duplicação conceitual (jogo/gol de campeonato vs pelada) em troca de isolamento total.
- Aceitamos recalcular a classificação a cada leitura (dezenas de jogos, custo desprezível) em troca de zero drift.
- Aceitamos um POST por gol durante o jogo em troca de não perder a súmula se o celular morrer.
- Aceitamos não ter link público agora em troca de não expor dados de terceiros sem decisão explícita.

## Alternativas consideradas

- Reusar `matches`/`goals`/`players` com colunas nulas de campeonato (candidato 2): interface rasa e vazamento — toda
  consulta existente precisaria filtrar; conflito com camisa única por conta.
- Classificação persistida atualizada por observers: duas fontes de verdade, confronto direto exige reler os jogos de
  qualquer forma.

## Perguntas em aberto e riscos

- Regulamento local: pontos por vitória na prorrogação, critérios de desempate e se há empate na fase de grupos — ficam
  configuráveis; o organizador deve confirmar antes do sorteio.
- Quantos classificados quando a conta não fecha potência de 2: usamos byes para os melhores seeds — confirmar se o
  organizador prefere "melhores terceiros".
- Link público do resumo: desejado? (exigiria token opt-in e revogável).

## Próximo passo e unidades verificáveis

1. U1 esquema+enums+DTOs+modelos+factories+testes de tenancy/constraints.
2. U2 `StandingsCalculator` e `BracketPlanner` puros + testes unitários.
3. U3 `TournamentEngine` (inscrição, regras, sorteio, agenda, eventos, finalizar, reabrir, desempate manual, chave, resumo) + testes.
4. U4 rotas, FormRequests, controllers, upload seguro + testes HTTP e de isolamento.
5. U5 páginas Vue, navegação, `types:check`, `build`, suíte completa.
