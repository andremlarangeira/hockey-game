<script setup lang="ts">
import PlayerPhoto from '@/components/hockey/PlayerPhoto.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Clock3,
    Expand,
    Shrink,
    Pause,
    Play,
    RotateCcw,
    Save,
    Settings2,
    Volume2,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useMatchClock } from '@/composables/useMatchClock';
import { useMatchAudio } from '@/composables/useMatchAudio';
import { useWakeLock } from '@/composables/useWakeLock';
import type {
    DrawResult,
    DrawTeam,
    GoalEvent,
    MatchSettings,
    Player,
    SoundSettings,
    TimelineEvent,
} from '@/types/hockey';
import { formatClock } from '@/lib/matchClock';

const page = usePage();
const draftKey = `hockey-rascunho:${page.props.team.id}`;
const props = defineProps<{
    settings: {
        minutes: number;
        periods: number;
        rest_seconds: number;
        countdown_seconds: number;
    };
    sounds: SoundSettings;
}>();
const config = ref({ ...props.settings });
const sounds = ref<SoundSettings>(
    JSON.parse(JSON.stringify(props.sounds)) as SoundSettings,
);
const showConfig = ref(true);
const teams = ref<DrawTeam[]>([]);
const selected = ref<{ home: string; away: string }>({ home: '', away: '' });
const roster = ref<{ home: DrawTeam; away: DrawTeam } | null>(null);
const goals = ref<GoalEvent[]>([]);
const timeline = ref<TimelineEvent[]>([]);
const dialog = ref<{ team: 'home' | 'away'; scorer: Player | null } | null>(
    null,
);
const saving = ref(false);
const error = ref('');
let recordedTransitions = 0;
const wake = useWakeLock();
const sound = useMatchAudio();
const clock = useMatchClock(
    config.value,
    () => recordTransitions(),
    () => sound.countdown(),
);
const homeScore = computed(
    () => goals.value.filter((goal) => goal.team === 'home').length,
);
const awayScore = computed(
    () => goals.value.filter((goal) => goal.team === 'away').length,
);
const availableHome = computed(() =>
    teams.value.filter((team) => team.id !== selected.value.away),
);
const availableAway = computed(() =>
    teams.value.filter((team) => team.id !== selected.value.home),
);
const currentSide = computed(() =>
    dialog.value ? (roster.value?.[dialog.value.team].players ?? []) : [],
);
const formattedTime = computed(() => formatClock(clock.state.remaining));
const disabledGoals = computed(
    () => clock.state.phase !== 'period' || clock.state.paused,
);
function readableColor(hex: string) {
    return hex === '#f59e0b' || hex === '#84cc16' ? '#111827' : '#fff';
}
const phaseLabel = computed(
    () =>
        ({
            idle: 'Pronto para jogar',
            countdown:
                clock.state.period > 1
                    ? `Preparem-se para o ${clock.state.period}º tempo!`
                    : 'Preparem-se!',
            period: `${clock.state.period}º tempo`,
            rest: 'Descanso',
            finished: 'Fim de partida',
        })[clock.state.phase],
);
const restoring = ref(true);
try {
    const saved = JSON.parse(
        sessionStorage.getItem('hockey-teams') ?? 'null',
    ) as DrawResult | null;
    if (saved?.teams?.length) {
        teams.value = saved.teams;
        selected.value = {
            home: saved.teams[0].id,
            away: saved.teams[1]?.id ?? '',
        };
    }
} catch {
    sessionStorage.removeItem('hockey-teams');
}

onMounted(() => {
    try {
        interface StoredDraft {
            roster: { home: DrawTeam; away: DrawTeam };
            goals: GoalEvent[];
            timeline: TimelineEvent[];
            config: MatchSettings;
            serializedClock: string;
        }
        const draft = JSON.parse(
            localStorage.getItem(draftKey) ?? 'null',
        ) as StoredDraft | null;
        if (draft && draft.roster?.home?.players) {
            roster.value = draft.roster;
            teams.value = [draft.roster.home, draft.roster.away];
            goals.value = draft.goals ?? [];
            timeline.value = draft.timeline ?? [];
            Object.assign(config.value, draft.config);
            clock.configure(config.value);
            const savedClock = JSON.parse(draft.serializedClock ?? 'null') as {
                transitions?: unknown[];
            } | null;
            recordedTransitions = Array.isArray(savedClock?.transitions)
                ? savedClock.transitions.length
                : 0;
            clock.restore(draft.serializedClock);
            clock.configure(config.value);
            recordTransitions();
            if (clock.state.phase !== 'idle') showConfig.value = false;
        }
    } catch {
        localStorage.removeItem(draftKey);
    }
    restoring.value = false;
});
function confirmTeams() {
    const home = teams.value.find((team) => team.id === selected.value.home);
    const away = teams.value.find((team) => team.id === selected.value.away);
    if (!home || !away || home.id === away.id) {
        error.value = 'Escolha duas equipes diferentes.';
        return;
    }
    roster.value = { home, away };
    goals.value = [];
    timeline.value = [];
    error.value = '';
    showConfig.value = true;
}
function rescaleConfiguration() {
    if (roster.value) roster.value = null;
    goals.value = [];
    timeline.value = [];
    error.value = '';
}
function prepare() {
    if (!roster.value) {
        error.value = 'Volte ao sorteio para escolher as equipes.';
        return;
    }
    clock.configure(config.value);
    showConfig.value = false;
}
async function toggleMatch() {
    await sound.unlock(sounds.value);
    if (clock.state.phase === 'idle') {
        clock.configure(config.value);
        clock.start();
        void wake.request();
        timeline.value.push({
            at: clock.state.startedAt ?? new Date().toISOString(),
            type: 'start',
            period: null,
            elapsed_seconds: 0,
        });
        if ((clock.state.phase as string) === 'countdown')
            recordClock('pre_start');
        else {
            recordClock('period_start');
            sound.play(sounds.value, 'start');
        }
    } else {
        const wasPaused = clock.state.paused;
        clock.togglePause();
        recordClock(wasPaused ? 'resume' : 'pause');
    }
}
function recordClock(type: string, at?: string) {
    timeline.value.push({
        at: at ?? new Date().toISOString(),
        type,
        period: clock.state.phase === 'idle' ? null : clock.state.period,
        elapsed_seconds: clock.state.elapsed,
    });
}
function recordTransitions() {
    for (const event of clock.transitions.slice(recordedTransitions)) {
        const period = event.period || null;
        const ended = event.ended ?? '';
        if (ended === 'period') {
            timeline.value.push({
                at: event.at,
                type: 'period_end',
                period,
                elapsed_seconds: event.elapsed,
            });
            if (!restoring.value) sound.play(sounds.value, 'end');
        }
        if (ended === 'rest') {
            timeline.value.push({
                at: event.at,
                type: 'rest_end',
                period,
                elapsed_seconds: event.elapsed,
            });
            if (!restoring.value) sound.play(sounds.value, 'rest_end');
        }
        if (ended === 'countdown' && !restoring.value)
            sound.play(sounds.value, 'start');
        if (event.started === 'period') {
            timeline.value.push({
                at: event.at,
                type: 'period_start',
                period: event.period,
                elapsed_seconds: event.elapsed,
            });
        }
        if (event.started === 'rest')
            timeline.value.push({
                at: event.at,
                type: 'rest_start',
                period: event.period,
                elapsed_seconds: event.elapsed,
            });
        if (event.started === 'finished') {
            timeline.value.push({
                at: event.at,
                type: 'finish',
                period: event.period,
                elapsed_seconds: event.elapsed,
            });
            void wake.release();
        }
    }
    recordedTransitions = clock.transitions.length;
}
function finishAndSave() {
    clock.finish();
    recordClock('finish');
    void wake.release();
    localStorage.removeItem(draftKey);
    save();
}
function resetMatch() {
    if (saving.value) return;
    if (
        clock.state.phase !== 'idle' &&
        !window.confirm(
            'Reiniciar esta partida e apagar os lances ainda não salvos?',
        )
    )
        return;
    clock.configure(config.value);
    clock.reset();
    localStorage.removeItem(draftKey);
    roster.value = null;
    showConfig.value = true;
    goals.value = [];
    timeline.value = [];
    recordedTransitions = 0;
    dialog.value = null;
    error.value = '';
    void wake.release();
}
watch(
    () => clock.running,
    (running) => {
        if (running && !clock.state.paused) void wake.request();
    },
);
watch(
    config,
    (value) => {
        clock.configure(value);
    },
    { deep: true },
);
watch(
    [roster, goals, timeline, config],
    () => {
        if (
            restoring.value ||
            !roster.value ||
            clock.state.phase === 'finished'
        )
            return;
        localStorage.setItem(
            draftKey,
            JSON.stringify({
                roster: roster.value,
                goals: goals.value,
                timeline: timeline.value,
                config: config.value,
                serializedClock: clock.export(),
            }),
        );
    },
    { deep: true },
);
const pageHide = () => {
    if (
        roster.value &&
        clock.state.phase !== 'idle' &&
        clock.state.phase !== 'finished'
    )
        localStorage.setItem(
            draftKey,
            JSON.stringify({
                roster: roster.value,
                goals: goals.value,
                timeline: timeline.value,
                config: config.value,
                serializedClock: clock.export(),
            }),
        );
};
window.addEventListener('pagehide', pageHide);
onBeforeUnmount(() => window.removeEventListener('pagehide', pageHide));
async function openGoal(team: 'home' | 'away') {
    if (clock.state.phase !== 'period' || clock.state.paused) return;
    await sound.unlock(sounds.value);
    if (clock.state.phase !== 'period' || clock.state.paused) return;
    dialog.value = { team, scorer: null };
}
function scorer(player: Player) {
    if (dialog.value) dialog.value.scorer = player;
}
function assist(player: Player | null) {
    const current = dialog.value;
    if (!current?.scorer || !roster.value) return;
    void sound.unlock(sounds.value);
    goals.value.push({
        id: crypto.randomUUID(),
        team: current.team,
        scorer_id: current.scorer.id,
        scorer_name: current.scorer.name,
        assist_id: player?.id ?? null,
        assist_name: player?.name ?? null,
        period: clock.state.period,
        elapsed_seconds: clock.state.elapsed,
        remaining_seconds: clock.state.remaining,
        recorded_at: new Date().toISOString(),
    });
    timeline.value.push({
        at: new Date().toISOString(),
        type: 'goal_added',
        period: clock.state.period,
        elapsed_seconds: clock.state.elapsed,
        details: { scorer: current.scorer.name },
    });
    if (clock.restartCountdown()) {
        timeline.value.push({
            at: new Date().toISOString(),
            type: 'pre_start',
            period: clock.state.period,
            elapsed_seconds: clock.state.elapsed,
            details: { motivo: 'gol' },
        });
    }
    dialog.value = null;
}
function undo() {
    const goal = goals.value.pop();
    if (goal)
        timeline.value.push({
            at: new Date().toISOString(),
            type: 'goal_removed',
            period: goal.period,
            elapsed_seconds: clock.state.elapsed,
            details: { scorer: goal.scorer_name },
        });
}
const isFullscreen = ref(false);
function syncFullscreen() {
    isFullscreen.value = Boolean(document.fullscreenElement);
}
onMounted(() => document.addEventListener('fullscreenchange', syncFullscreen));
onBeforeUnmount(() =>
    document.removeEventListener('fullscreenchange', syncFullscreen),
);
async function toggleFullscreen() {
    try {
        if (document.fullscreenElement) await document.exitFullscreen();
        else {
            await document.documentElement.requestFullscreen();
            if ('orientation' in screen)
                await (
                    screen.orientation as ScreenOrientation & {
                        lock?: (orientation: string) => Promise<void>;
                    }
                )
                    .lock?.('landscape')
                    .catch(() => undefined);
        }
        return;
    } catch {
        /* O navegador pode não permitir tela cheia/orientação. */
    }
}
function upload(event: Event, key: 'start' | 'end' | 'rest_end') {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (
        !file ||
        typeof file.type !== 'string' ||
        !['audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/ogg'].includes(
            file.type,
        )
    ) {
        error.value = 'Escolha um áudio MP3, WAV ou OGG.';
        return;
    }
    if (file.size > 4 * 1024 * 1024) {
        error.value = 'O som deve ter no máximo 4 MB.';
        return;
    }
    sounds.value[key] = {
        type: 'custom',
        file: URL.createObjectURL(file),
        name: file.name,
    };
}
function save() {
    if (saving.value) return;
    if (!roster.value || !clock.state.startedAt) {
        error.value = 'Comece a partida antes de salvar.';
        return;
    }
    saving.value = true;
    error.value = '';
    router.post(
        '/partidas',
        {
            roster: roster.value,
            settings: config.value,
            goals: goals.value,
            timeline: timeline.value,
            home_score: homeScore.value,
            away_score: awayScore.value,
            started_at: clock.state.startedAt,
            finished_at: new Date().toISOString(),
        },
        {
            onError: (errors) => {
                error.value =
                    Object.values(errors)[0] || 'Confira os dados da partida.';
                saving.value = false;
            },
            onSuccess: () => {
                sessionStorage.removeItem('hockey-teams');
                localStorage.removeItem(draftKey);
            },
            preserveScroll: true,
        },
    );
}
onBeforeUnmount(() => {
    void wake.release();
});
</script>

<template>
    <Head title="Cronômetro e placar" />
    <div
        v-if="!isFullscreen"
        class="mb-4 flex items-start justify-between gap-3"
    >
        <div>
            <p
                class="text-xs font-bold tracking-widest text-slate-500 uppercase dark:text-slate-400"
            >
                Controle da partida
            </p>
            <h1 class="mt-1 text-2xl font-black">Cronômetro e placar</h1>
        </div>
        <button
            class="flex size-12 items-center justify-center rounded-xl border border-slate-300 bg-white dark:border-slate-600 dark:bg-slate-900"
            aria-label="Configurações da partida"
            @click="showConfig = !showConfig"
        >
            <Settings2 />
        </button>
    </div>
    <div
        v-if="error"
        class="mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700"
    >
        {{ error }}
        <Link v-if="!roster" href="/sorteio" class="font-bold underline"
            >Ir para o sorteio</Link
        >
    </div>
    <section
        v-if="showConfig"
        class="mb-4 space-y-5 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
    >
        <div v-if="!roster && teams.length >= 2">
            <h2 class="font-black">Quem entra na quadra?</h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <label class="text-sm font-bold"
                    >Equipe A<select
                        v-model="selected.home"
                        @change="rescaleConfiguration()"
                        class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 dark:border-slate-600 dark:bg-slate-900"
                        data-test="equipe-a"
                    >
                        <option
                            v-for="team in availableHome"
                            :key="team.id"
                            :value="team.id"
                        >
                            {{ team.name }}
                        </option>
                    </select></label
                ><label class="text-sm font-bold"
                    >Equipe B<select
                        v-model="selected.away"
                        @change="rescaleConfiguration()"
                        class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 dark:border-slate-600 dark:bg-slate-900"
                        data-test="equipe-b"
                    >
                        <option
                            v-for="team in availableAway"
                            :key="team.id"
                            :value="team.id"
                        >
                            {{ team.name }}
                        </option>
                    </select></label
                >
            </div>
            <button
                class="mt-4 min-h-12 w-full rounded-xl bg-[var(--team-primary)] font-bold text-[var(--team-primary-text)]"
                @click="confirmTeams()"
            >
                Confirmar equipes
            </button>
        </div>
        <div
            v-else-if="!roster"
            class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900"
        >
            Faça o sorteio antes de preparar a partida.
            <Link
                href="/sorteio"
                class="block min-h-10 pt-3 font-bold underline"
                >Ir para o sorteio</Link
            >
        </div>
        <div v-if="roster">
            <div class="grid grid-cols-2 gap-3">
                <div
                    class="rounded-xl p-3 text-center font-bold text-white"
                    :style="{
                        background: roster.home.color,
                        color: readableColor(roster.home.color),
                    }"
                >
                    {{ roster.home.name }}
                </div>
                <div
                    class="rounded-xl p-3 text-center font-bold text-white"
                    :style="{
                        background: roster.away.color,
                        color: readableColor(roster.away.color),
                    }"
                >
                    {{ roster.away.name }}
                </div>
            </div>
        </div>
        <div v-if="roster">
            <h2 class="font-black">Duração do jogo</h2>
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <label class="text-xs font-bold"
                    >Minutos por tempo<input
                        v-model.number="config.minutes"
                        type="number"
                        min="1"
                        max="120"
                        class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-600"
                        data-test="minutos" /></label
                ><label class="text-xs font-bold"
                    >Número de tempos<input
                        v-model.number="config.periods"
                        type="number"
                        min="1"
                        max="6"
                        class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-600" /></label
                ><label class="text-xs font-bold"
                    >Descanso (segundos)<input
                        v-model.number="config.rest_seconds"
                        type="number"
                        min="1"
                        max="3600"
                        class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-600"
                        data-test="descanso" /></label
                ><label class="text-xs font-bold"
                    >Início (segundos)<input
                        v-model.number="config.countdown_seconds"
                        type="number"
                        min="0"
                        max="120"
                        class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-600"
                        data-test="contagem"
                /></label>
            </div>
        </div>
        <div v-if="roster">
            <h2 class="font-black">Sons da partida</h2>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                Apito no início, buzzer grave no fim e bipes nos últimos 5
                segundos de cada contagem. Seus arquivos substituem os sons de
                início, fim e descanso.
            </p>
            <div class="mt-2 space-y-3">
                <div
                    v-for="(label, key) in {
                        start: 'Início de tempo / partida',
                        end: 'Fim de tempo / partida',
                        rest_end: 'Fim do descanso',
                    }"
                    :key="key"
                    class="flex flex-wrap items-center gap-2 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50"
                >
                    <span class="min-w-40 flex-1 text-sm font-bold">{{
                        label
                    }}</span
                    ><label
                        class="cursor-pointer rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-bold dark:border-slate-600 dark:bg-slate-900"
                        >{{
                            sounds[key].type === 'custom'
                                ? sounds[key].name
                                : 'Trocar som'
                        }}<input
                            class="sr-only"
                            type="file"
                            accept="audio/mpeg,audio/wav,audio/ogg"
                            @change="upload($event, key)" /></label
                    ><button
                        type="button"
                        class="flex min-h-10 items-center gap-1 rounded-lg bg-slate-900 px-3 text-xs font-bold text-white dark:bg-slate-700"
                        :aria-label="`Testar som: ${label}`"
                        @click="
                            sound
                                .unlock(sounds)
                                .then(() => sound.play(sounds, key))
                        "
                    >
                        <Volume2 class="size-4" />Testar
                    </button>
                </div>
            </div>
        </div>
        <button
            v-if="roster"
            class="min-h-14 w-full rounded-xl bg-[var(--team-primary)] text-lg font-black text-[var(--team-primary-text)]"
            data-test="abrir-placar"
            @click="prepare"
        >
            Abrir placar
        </button>
    </section>
    <template v-if="!showConfig && roster">
        <section
            class="overflow-hidden bg-slate-950 text-white shadow-2xl"
            :class="
                isFullscreen
                    ? 'fixed inset-0 z-[45] flex flex-col'
                    : 'rounded-2xl'
            "
            data-test="placar"
        >
            <div
                class="flex items-center justify-between border-b border-white/10 px-3 py-2"
            >
                <span
                    class="text-xs font-bold tracking-widest text-slate-400 uppercase"
                    >{{ phaseLabel }}</span
                >
                <div class="flex items-center gap-1">
                    <button
                        class="flex min-h-10 items-center gap-1 px-2 text-xs"
                        title="Manter tela acesa"
                        :aria-pressed="wake.active.value"
                        @click="
                            wake.active.value ? wake.release() : wake.request()
                        "
                    >
                        <Clock3 class="size-4" /><span class="hidden sm:inline"
                            >Tela acesa
                            {{
                                wake.active.value ? 'ativada' : 'desativada'
                            }}</span
                        ></button
                    ><button
                        class="flex min-h-10 items-center gap-1 px-2 text-xs"
                        data-test="tela-cheia"
                        @click="toggleFullscreen"
                    >
                        <template v-if="isFullscreen"
                            ><Shrink class="size-4" />Sair da tela
                            cheia</template
                        ><template v-else
                            ><Expand class="size-4" />Tela cheia</template
                        >
                    </button>
                </div>
            </div>
            <div
                v-if="clock.state.phase === 'countdown'"
                class="bg-amber-300 px-3 py-2 text-center"
                data-test="contagem-gol"
            >
                <span
                    class="block text-xs font-black tracking-widest text-amber-950 uppercase"
                >
                    {{ goals.length ? 'Após o gol' : 'Início da partida' }}
                </span>
                <strong
                    class="block text-4xl font-black text-amber-950 tabular-nums"
                >
                    {{ clock.state.remaining }}
                </strong>
                <span class="text-xs font-bold text-amber-950">
                    Preparem-se para o apito
                </span>
            </div>
            <div
                class="grid grid-cols-[1fr_auto_1fr] items-center gap-2 p-3 sm:gap-6 sm:p-5 landscape-short:gap-1 landscape-short:p-2"
                :class="{ 'flex-1': isFullscreen }"
            >
                <div class="text-center">
                    <h2
                        class="truncate font-black"
                        :class="
                            isFullscreen
                                ? 'text-[min(6vh,4vw)]'
                                : 'text-sm sm:text-xl'
                        "
                    >
                        {{ roster.home.name }}
                    </h2>
                    <strong
                        class="block font-black tabular-nums"
                        :class="
                            isFullscreen
                                ? 'text-[min(40vh,22vw)] leading-none'
                                : 'text-6xl sm:text-8xl'
                        "
                        data-test="placar-casa"
                        >{{ homeScore }}</strong
                    >
                </div>
                <div class="text-center">
                    <p
                        class="font-black text-amber-300 tabular-nums"
                        :class="
                            isFullscreen
                                ? 'text-[min(24vh,14vw)] leading-none'
                                : 'text-4xl sm:text-6xl'
                        "
                        data-test="cronometro"
                    >
                        {{ formattedTime }}
                    </p>
                    <span class="text-xs text-slate-400"
                        >{{ config.minutes }} min / tempo</span
                    >
                </div>
                <div class="text-center">
                    <h2
                        class="truncate font-black"
                        :class="
                            isFullscreen
                                ? 'text-[min(6vh,4vw)]'
                                : 'text-sm sm:text-xl'
                        "
                    >
                        {{ roster.away.name }}
                    </h2>
                    <strong
                        class="block font-black tabular-nums"
                        :class="
                            isFullscreen
                                ? 'text-[min(40vh,22vw)] leading-none'
                                : 'text-6xl sm:text-8xl'
                        "
                        data-test="placar-fora"
                        >{{ awayScore }}</strong
                    >
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3 p-3">
                <button
                    class="min-h-16 rounded-xl text-xl font-black text-white disabled:opacity-30 sm:min-h-20 sm:text-2xl"
                    :style="{
                        background: roster.home.color,
                        color: readableColor(roster.home.color),
                    }"
                    :disabled="disabledGoals"
                    data-test="gol-casa"
                    @click="openGoal('home')"
                >
                    + GOL</button
                ><button
                    class="min-h-16 rounded-xl text-xl font-black text-white disabled:opacity-30 sm:min-h-20 sm:text-2xl"
                    :style="{
                        background: roster.away.color,
                        color: readableColor(roster.away.color),
                    }"
                    :disabled="disabledGoals"
                    data-test="gol-fora"
                    @click="openGoal('away')"
                >
                    + GOL
                </button>
            </div>
            <div class="flex gap-2 px-3 pb-3">
                <button
                    v-if="clock.state.phase !== 'finished'"
                    class="flex min-h-12 flex-1 items-center justify-center gap-2 rounded-xl bg-[var(--team-primary)] font-bold text-[var(--team-primary-text)]"
                    data-test="iniciar-cronometro"
                    @click="toggleMatch"
                >
                    <Pause v-if="clock.running && !clock.state.paused" /><Play
                        v-else
                    />{{
                        clock.state.phase === 'idle'
                            ? 'Iniciar partida'
                            : clock.state.paused
                              ? 'Continuar'
                              : 'Pausar'
                    }}</button
                ><button
                    class="flex min-h-12 items-center gap-2 rounded-xl border border-white/20 px-3 text-sm font-bold"
                    :disabled="!goals.length"
                    @click="undo"
                >
                    <RotateCcw class="size-4" />Desfazer gol</button
                ><button
                    class="flex size-12 items-center justify-center rounded-xl border border-white/20"
                    aria-label="Reiniciar"
                    @click="resetMatch()"
                >
                    <RotateCcw class="size-4" />
                </button>
            </div>
        </section>
        <div v-if="!isFullscreen" class="mt-3 flex gap-2">
            <button
                class="flex min-h-12 flex-1 items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white text-sm font-bold disabled:opacity-50 dark:border-slate-600 dark:bg-slate-900"
                :disabled="saving"
                @click="
                    clock.state.phase === 'finished' ? save() : finishAndSave()
                "
            >
                <Save class="size-5" />Salvar partida
            </button>
        </div>
        <section
            v-if="!isFullscreen"
            class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
        >
            <h2 class="font-black">Gols marcados</h2>
            <p
                v-if="!goals.length"
                class="mt-3 text-sm text-slate-500 dark:text-slate-400"
            >
                Os gols aparecem aqui com autor, assistência e tempo.
            </p>
            <div
                v-for="goal in [...goals].reverse()"
                :key="goal.id"
                class="mt-3 flex items-center gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50"
            >
                <span
                    class="flex size-10 items-center justify-center rounded-lg font-black text-white"
                    :style="{ background: roster[goal.team].color }"
                    >{{ goal.period }}º</span
                >
                <div class="min-w-0 flex-1">
                    <p class="font-bold">{{ goal.scorer_name }}</p>
                    <p
                        class="truncate text-xs text-slate-500 dark:text-slate-400"
                    >
                        {{
                            goal.assist_name
                                ? `Assistência: ${goal.assist_name}`
                                : 'Sem assistência'
                        }}
                    </p>
                </div>
                <span class="text-sm font-black tabular-nums">{{
                    formatClock(goal.remaining_seconds)
                }}</span>
            </div>
        </section>
    </template>
    <Teleport to="body"
        ><div
            v-if="dialog"
            class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/85 sm:items-center sm:p-6"
        >
            <div
                class="max-h-[90svh] w-full max-w-3xl overflow-y-auto rounded-t-3xl bg-white p-5 sm:rounded-3xl dark:bg-slate-900"
            >
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-2xl font-black">
                            {{
                                dialog.scorer
                                    ? 'Quem deu a assistência?'
                                    : 'Quem fez o gol?'
                            }}
                        </h2>
                        <p
                            v-if="dialog.scorer"
                            class="mt-1 text-sm text-slate-500 dark:text-slate-400"
                        >
                            Gol de {{ dialog.scorer.name }}
                        </p>
                    </div>
                    <button
                        aria-label="Cancelar lançamento"
                        @click="dialog = null"
                    >
                        <X />
                    </button>
                </div>
                <div
                    v-if="!dialog.scorer"
                    class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3"
                >
                    <button
                        v-for="player in currentSide"
                        :key="player.id"
                        class="flex min-h-24 cursor-pointer items-center gap-3 rounded-2xl border-2 border-slate-200 p-3 text-left font-bold hover:border-[var(--team-primary)] dark:border-slate-700"
                        :data-scorer-id="player.id"
                        @click="scorer(player)"
                    >
                        <PlayerPhoto :player="player" size="size-14" /><span
                            ><strong class="block text-xl"
                                >#{{ player.jersey_number }}</strong
                            >{{ player.name }}</span
                        >
                    </button>
                </div>
                <div v-else class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <button
                        class="col-span-full flex min-h-14 items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-slate-300 font-bold dark:border-slate-600"
                        data-test="sem-assistencia"
                        @click="assist(null)"
                    >
                        Sem assistência</button
                    ><button
                        v-for="player in currentSide.filter(
                            (p) => p.id !== dialog?.scorer?.id,
                        )"
                        :key="player.id"
                        class="flex min-h-24 items-center gap-3 rounded-2xl border-2 border-slate-200 p-3 text-left font-bold dark:border-slate-700"
                        :data-assist-id="player.id"
                        @click="assist(player)"
                    >
                        <PlayerPhoto :player="player" size="size-14" /><span
                            >#{{ player.jersey_number }} {{ player.name }}</span
                        >
                    </button>
                </div>
            </div>
        </div></Teleport
    >
</template>
