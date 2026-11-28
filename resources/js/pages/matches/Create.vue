<script setup lang="ts">
import PlayerPhoto from '@/components/hockey/PlayerPhoto.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useFullscreen } from '@/composables/useFullscreen';
import { useMatchAudio } from '@/composables/useMatchAudio';
import { useMatchClock } from '@/composables/useMatchClock';
import { useToast } from '@/composables/useToast';
import { useWakeLock } from '@/composables/useWakeLock';
import {
    applyTeamColorsToRoster,
    applyTeamColorsToTeams,
    readableText,
} from '@/lib/balancedDraw';
import { load as loadDraw, save as saveDraw } from '@/lib/drawStorage';
import { formatClock } from '@/lib/matchClock';
import { transitionSounds } from '@/lib/matchClockCore';
import { loadQueue } from '@/lib/matchQueue';
import { enqueueMatchBeforeClearingDraft } from '@/lib/matchSave';
import {
    offlineStatus,
    openPendingMatchesPanel,
    refreshOfflineStatus,
    syncNow,
} from '@/lib/offlineSync';
import type {
    DrawResult,
    DrawTeam,
    GoalEvent,
    MatchSettings,
    Player,
    SoundSettings,
    TimelineEvent,
} from '@/types/hockey';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowLeftRight,
    Clock3,
    Expand,
    Megaphone,
    Pause,
    Play,
    RotateCcw,
    Save,
    Settings2,
    Shrink,
    Timer,
    Undo2,
    Volume2,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const page = usePage();
const { confirm } = useConfirm();
const toast = useToast();
const draftKey = `hockey-rascunho:${page.props.team.id}`;
const prefsKey = `hockey-config-preferencias:${page.props.team.id}`;

const props = defineProps<{
    settings: {
        minutes: number;
        periods: number;
        rest_seconds: number;
        countdown_seconds: number;
    };
    sounds: SoundSettings;
}>();

function loadInitialSettings(): MatchSettings {
    try {
        const saved = JSON.parse(
            localStorage.getItem(prefsKey) ?? 'null',
        ) as Partial<MatchSettings> | null;
        if (saved && typeof saved.minutes === 'number') {
            return {
                minutes: saved.minutes,
                periods: saved.periods ?? props.settings.periods,
                rest_seconds: saved.rest_seconds ?? props.settings.rest_seconds,
                countdown_seconds:
                    saved.countdown_seconds ?? props.settings.countdown_seconds,
            };
        }
    } catch {
        // Ignora preferência local inválida.
    }
    return { ...props.settings };
}

const config = ref<MatchSettings>(loadInitialSettings());
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
const queuedForCurrentMatch = ref(false);
const error = ref('');
const notice = ref('');
const lastScoredSide = ref<'home' | 'away' | null>(null);
const whistlePulse = ref(false);
let scoreAnimTimer: ReturnType<typeof setTimeout> | undefined;

const queued = computed(() => {
    void offlineStatus.value.revision;
    return loadQueue(localStorage, page.props.team.id).length;
});
let recordedTransitions = 0;
const wake = useWakeLock();
const sound = useMatchAudio();
const clock = useMatchClock(
    config.value,
    () => recordTransitions(),
    () => sound.countdown(),
);
const { isFullscreen, toggleFullscreen } = useFullscreen();

const homeScore = computed(
    () => goals.value.filter((goal) => goal.team === 'home').length,
);
const awayScore = computed(
    () => goals.value.filter((goal) => goal.team === 'away').length,
);
const lastGoal = computed(() => goals.value[goals.value.length - 1] ?? null);

const availableHome = computed(() =>
    teams.value.filter((team) => team.id !== selected.value.away),
);
const availableAway = computed(() =>
    teams.value.filter((team) => team.id !== selected.value.home),
);
const currentSide = computed(() => {
    const players = dialog.value
        ? (roster.value?.[dialog.value.team].players ?? [])
        : [];
    return [...players].sort((a, b) => a.jersey_number - b.jersey_number);
});
const formattedTime = computed(() => formatClock(clock.state.remaining));
const disabledGoals = computed(
    () => clock.state.phase !== 'period' || clock.state.paused,
);

const phaseProgress = computed(() => {
    if (clock.state.phase === 'period') {
        const totalSeconds = Math.max(1, config.value.minutes * 60);
        return Math.max(
            0,
            Math.min(100, (clock.state.remaining / totalSeconds) * 100),
        );
    }
    if (clock.state.phase === 'rest') {
        const totalRest = Math.max(1, config.value.rest_seconds);
        return Math.max(
            0,
            Math.min(100, (clock.state.remaining / totalRest) * 100),
        );
    }
    if (clock.state.phase === 'countdown') {
        const totalCd = Math.max(1, config.value.countdown_seconds);
        return Math.max(
            0,
            Math.min(100, (clock.state.remaining / totalCd) * 100),
        );
    }
    if (clock.state.phase === 'finished') return 0;
    return 100;
});

const isCriticalTime = computed(
    () => clock.state.phase === 'period' && clock.state.remaining <= 10,
);
const isWarningTime = computed(
    () =>
        clock.state.phase === 'period' &&
        clock.state.remaining > 10 &&
        clock.state.remaining <= 30,
);

function readableColor(hex: string) {
    return readableText(hex);
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

const countdownLabel = computed(() => {
    switch (clock.state.countdownReason) {
        case 'goal':
            return 'Após o gol';
        case 'restart':
            return 'Reinício';
        case 'next_period':
            return 'Próximo tempo';
        default:
            return 'Início da partida';
    }
});

const restoring = ref(true);
let rawDrawTeams: DrawTeam[] = [];
try {
    const savedLocal = loadDraw(localStorage, page.props.team.id);
    const savedSession = !savedLocal
        ? (JSON.parse(
              sessionStorage.getItem('hockey-teams') ?? 'null',
          ) as DrawResult | null)
        : null;
    const saved = savedLocal ?? savedSession;
    if (saved?.teams?.length) {
        rawDrawTeams = saved.teams;
        teams.value = applyTeamColorsToTeams(
            saved.teams,
            page.props.team?.primary_color,
            page.props.team?.secondary_color,
        );
        selected.value = {
            home: teams.value[0]!.id,
            away: teams.value[1]?.id ?? '',
        };
        if (savedLocal) {
            saveDraw(localStorage, page.props.team.id, {
                teams: teams.value,
                reserves: savedLocal.reserves,
                mode: savedLocal.mode,
                size: savedLocal.size,
            });
        }
        if (sessionStorage.getItem('hockey-teams')) {
            sessionStorage.setItem(
                'hockey-teams',
                JSON.stringify({
                    teams: teams.value,
                    reserves: saved.reserves ?? [],
                }),
            );
        }
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
            roster.value = applyTeamColorsToRoster(
                draft.roster,
                page.props.team?.primary_color,
                page.props.team?.secondary_color,
                rawDrawTeams,
            );
            if (!teams.value.length) {
                teams.value = [roster.value.home, roster.value.away];
            }
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
            if (clock.state.phase !== 'finished') {
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
            }
        }
    } catch {
        localStorage.removeItem(draftKey);
    }
    restoring.value = false;
});

watch(
    () => [page.props.team?.primary_color, page.props.team?.secondary_color],
    ([primaryColor, secondaryColor]) => {
        if (teams.value.length) {
            teams.value = applyTeamColorsToTeams(
                teams.value,
                primaryColor,
                secondaryColor,
            );
        }
        if (roster.value) {
            roster.value = applyTeamColorsToRoster(
                roster.value,
                primaryColor,
                secondaryColor,
                rawDrawTeams,
            );
        }
    },
);

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

function quickOpenScoreboard() {
    confirmTeams();
    if (roster.value) {
        prepare();
    }
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
    try {
        localStorage.setItem(prefsKey, JSON.stringify(config.value));
    } catch {
        // Ignora erro de storage.
    }
    clock.configure(config.value);
    showConfig.value = false;
}

function swapSides() {
    if (!roster.value) return;
    roster.value = {
        home: roster.value.away,
        away: roster.value.home,
    };
    goals.value = goals.value.map((goal) => ({
        ...goal,
        team: goal.team === 'home' ? 'away' : 'home',
    }));
    toast.info('Lados invertidos no placar.', 1800);
}

async function blowWhistle() {
    whistlePulse.value = true;
    await sound.unlock(sounds.value);
    sound.play(sounds.value, 'start');
    window.setTimeout(() => {
        whistlePulse.value = false;
    }, 500);
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

async function restartWithCountdown() {
    if (clock.state.phase !== 'period') return;
    await sound.unlock(sounds.value);
    if (clock.state.phase !== 'period') return;
    if (clock.restartCountdown('restart')) {
        void wake.request();
        timeline.value.push({
            at: new Date().toISOString(),
            type: 'pre_start',
            period: clock.state.period,
            elapsed_seconds: clock.state.elapsed,
            details: { motivo: 'reinicio' },
        });
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
        }
        if (ended === 'rest') {
            timeline.value.push({
                at: event.at,
                type: 'rest_end',
                period,
                elapsed_seconds: event.elapsed,
            });
        }
        if (!restoring.value)
            for (const soundName of transitionSounds(event))
                sound.play(sounds.value, soundName);
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
    void save();
}

async function resetMatch() {
    if (saving.value) return;
    if (clock.state.phase !== 'idle' && !queuedForCurrentMatch.value) {
        const accepted = await confirm({
            title: 'Reiniciar partida?',
            message:
                'Todos os gols, assistências e o tempo desta partida ainda não salva serão apagados.',
            confirmLabel: 'Reiniciar partida',
            variant: 'danger',
        });
        if (!accepted) return;
    }
    clock.configure(config.value);
    clock.reset();
    sound.stop();
    localStorage.removeItem(draftKey);
    roster.value = null;
    showConfig.value = true;
    goals.value = [];
    timeline.value = [];
    recordedTransitions = 0;
    dialog.value = null;
    error.value = '';
    notice.value = '';
    queuedForCurrentMatch.value = false;
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

function onKeydown(event: KeyboardEvent) {
    if (dialog.value) {
        if (event.key === 'Escape') {
            dialog.value = null;
        }
        return;
    }
    if (showConfig.value || !roster.value) return;
    const tag = (event.target as HTMLElement | null)?.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

    if (event.code === 'Space' && clock.state.phase !== 'finished') {
        event.preventDefault();
        void toggleMatch();
    } else if (event.key.toLowerCase() === 'w') {
        event.preventDefault();
        void blowWhistle();
    } else if (event.key.toLowerCase() === 'f') {
        event.preventDefault();
        void toggleFullscreen();
    }
}

window.addEventListener('pagehide', pageHide);
onMounted(() => {
    window.addEventListener('keydown', onKeydown);
});
onBeforeUnmount(() => {
    window.removeEventListener('pagehide', pageHide);
    window.removeEventListener('keydown', onKeydown);
    if (scoreAnimTimer) clearTimeout(scoreAnimTimer);
    void wake.release();
});

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

    lastScoredSide.value = current.team;
    if (scoreAnimTimer) clearTimeout(scoreAnimTimer);
    scoreAnimTimer = setTimeout(() => {
        lastScoredSide.value = null;
    }, 700);

    if (!clock.state.paused && clock.restartCountdown('goal')) {
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
    if (goal) {
        timeline.value.push({
            at: new Date().toISOString(),
            type: 'goal_removed',
            period: goal.period,
            elapsed_seconds: clock.state.elapsed,
            details: { scorer: goal.scorer_name },
        });
        toast.info(`Gol de ${goal.scorer_name} desfeito.`, 2000);
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

function matchPayload(): Record<string, unknown> | null {
    if (!roster.value || !clock.state.startedAt) {
        error.value = 'Comece a partida antes de salvar.';
        return null;
    }
    return JSON.parse(
        JSON.stringify({
            roster: roster.value,
            settings: config.value,
            goals: goals.value,
            timeline: timeline.value,
            home_score: homeScore.value,
            away_score: awayScore.value,
            started_at: clock.state.startedAt,
            finished_at: new Date().toISOString(),
        }),
    ) as Record<string, unknown>;
}

async function save() {
    if (saving.value || queuedForCurrentMatch.value) return;
    const payload = matchPayload();
    if (!payload) return;
    saving.value = true;
    error.value = '';
    let queuedItem;
    try {
        queuedItem = enqueueMatchBeforeClearingDraft(
            localStorage,
            page.props.team.id,
            payload,
            draftKey,
        );
    } catch {
        error.value =
            'Não foi possível guardar a partida no aparelho. O rascunho foi mantido.';
        saving.value = false;
        return;
    }
    queuedForCurrentMatch.value = true;
    sessionStorage.removeItem('hockey-teams');
    refreshOfflineStatus();
    notice.value =
        'Partida guardada no aparelho. Será enviada quando houver conexão.';
    const uploaded = await syncNow();
    saving.value = false;
    const saved = uploaded.find(
        (item) => item.client_uuid === queuedItem.client_uuid,
    );
    if (saved?.url) router.visit(saved.url);
}
</script>

<template>
    <Head title="Cronômetro e placar" />

    <div v-if="!isFullscreen" class="mb-4 flex items-end justify-between gap-3">
        <div>
            <p
                class="text-[11px] font-extrabold tracking-widest text-[var(--team-primary)] uppercase dark:text-emerald-400"
            >
                Controle da partida
            </p>
            <h1
                class="mt-0.5 text-2xl font-extrabold tracking-tight text-slate-950 sm:text-3xl dark:text-white"
            >
                Cronômetro e placar
            </h1>
        </div>
        <button
            type="button"
            class="flex size-11 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 shadow-xs transition-colors hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
            aria-label="Configurações da partida"
            @click="showConfig = !showConfig"
        >
            <Settings2 class="size-5" />
        </button>
    </div>

    <div
        v-if="error"
        class="mb-4 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700 dark:border-red-800/60 dark:bg-red-950/50 dark:text-red-200"
    >
        {{ error }}
        <Link v-if="!roster" href="/sorteio" class="ml-1 font-bold underline"
            >Ir para o sorteio</Link
        >
    </div>

    <div
        v-if="notice"
        class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/60 dark:text-emerald-200"
        role="status"
    >
        <span>{{ notice }}</span>
        <button
            type="button"
            class="rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-bold text-white transition-colors hover:bg-emerald-600"
            @click="resetMatch"
        >
            Iniciar nova partida
        </button>
    </div>

    <section
        v-if="showConfig"
        class="surface-card mb-4 space-y-5 rounded-2xl p-4 sm:p-5"
    >
        <div v-if="!roster && teams.length >= 2">
            <h2 class="text-base font-extrabold text-slate-950 dark:text-white">
                Quem entra na quadra?
            </h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <label
                    class="text-sm font-bold text-slate-800 dark:text-slate-200"
                    >Equipe A
                    <select
                        v-model="selected.home"
                        class="mt-1.5 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3.5 font-semibold text-slate-950 focus:border-[var(--team-primary)] focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        data-test="equipe-a"
                        @change="rescaleConfiguration()"
                    >
                        <option
                            v-for="team in availableHome"
                            :key="team.id"
                            :value="team.id"
                        >
                            {{ team.name }}
                        </option>
                    </select>
                </label>
                <label
                    class="text-sm font-bold text-slate-800 dark:text-slate-200"
                    >Equipe B
                    <select
                        v-model="selected.away"
                        class="mt-1.5 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3.5 font-semibold text-slate-950 focus:border-[var(--team-primary)] focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        data-test="equipe-b"
                        @change="rescaleConfiguration()"
                    >
                        <option
                            v-for="team in availableAway"
                            :key="team.id"
                            :value="team.id"
                        >
                            {{ team.name }}
                        </option>
                    </select>
                </label>
            </div>
            <div class="mt-4 flex flex-col gap-2.5 sm:flex-row">
                <button
                    type="button"
                    class="min-h-12 flex-1 rounded-xl bg-[var(--team-primary)] font-bold text-[var(--team-primary-text)] shadow-sm transition-all hover:brightness-110 active:scale-[0.98]"
                    @click="confirmTeams()"
                >
                    Confirmar equipes
                </button>
                <button
                    type="button"
                    class="min-h-12 rounded-xl border border-slate-300 bg-slate-50 px-4 text-sm font-bold text-slate-800 transition-colors hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
                    @click="quickOpenScoreboard()"
                >
                    Ir direto ao placar
                </button>
            </div>
        </div>

        <div
            v-else-if="!roster"
            class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-medium text-amber-900 dark:border-amber-800/60 dark:bg-amber-950/40 dark:text-amber-100"
        >
            Faça o sorteio antes de preparar a partida.
            <Link href="/sorteio" class="mt-2 block min-h-9 font-bold underline"
                >Ir para o sorteio →</Link
            >
        </div>

        <div v-if="roster">
            <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-2">
                <div
                    class="rounded-xl p-3 text-center font-extrabold shadow-xs"
                    :style="{
                        background: roster.home.color,
                        color: readableColor(roster.home.color),
                    }"
                >
                    {{ roster.home.name }}
                </div>
                <span class="font-display text-xl font-extrabold text-slate-400"
                    >VS</span
                >
                <div
                    class="rounded-xl p-3 text-center font-extrabold shadow-xs"
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
            <h2 class="text-base font-extrabold text-slate-950 dark:text-white">
                Duração do jogo
            </h2>
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <label
                    class="text-xs font-bold text-slate-700 dark:text-slate-300"
                    >Minutos por tempo
                    <input
                        v-model.number="config.minutes"
                        type="number"
                        min="1"
                        max="120"
                        class="mt-1.5 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm font-bold text-slate-950 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        data-test="minutos"
                    />
                </label>
                <label
                    class="text-xs font-bold text-slate-700 dark:text-slate-300"
                    >Número de tempos
                    <input
                        v-model.number="config.periods"
                        type="number"
                        min="1"
                        max="6"
                        class="mt-1.5 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm font-bold text-slate-950 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                    />
                </label>
                <label
                    class="text-xs font-bold text-slate-700 dark:text-slate-300"
                    >Descanso (segundos)
                    <input
                        v-model.number="config.rest_seconds"
                        type="number"
                        min="1"
                        max="3600"
                        class="mt-1.5 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm font-bold text-slate-950 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        data-test="descanso"
                    />
                </label>
                <label
                    class="text-xs font-bold text-slate-700 dark:text-slate-300"
                    >Início (segundos)
                    <input
                        v-model.number="config.countdown_seconds"
                        type="number"
                        min="0"
                        max="120"
                        class="mt-1.5 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm font-bold text-slate-950 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        data-test="contagem"
                    />
                </label>
            </div>
        </div>

        <div v-if="roster">
            <h2 class="text-base font-extrabold text-slate-950 dark:text-white">
                Sons da partida
            </h2>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                Apito no início, buzzer grave no fim e bipes nos últimos 5
                segundos de cada contagem.
            </p>
            <div class="mt-2.5 space-y-2.5">
                <div
                    v-for="(label, key) in {
                        start: 'Início de tempo / partida',
                        end: 'Fim de tempo / partida',
                        rest_end: 'Fim do descanso',
                    }"
                    :key="key"
                    class="flex flex-wrap items-center gap-2 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50"
                >
                    <span
                        class="min-w-40 flex-1 text-sm font-bold text-slate-800 dark:text-slate-200"
                        >{{ label }}</span
                    >
                    <label
                        class="cursor-pointer rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition-colors hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200"
                    >
                        {{
                            sounds[key].type === 'custom'
                                ? sounds[key].name
                                : 'Trocar som'
                        }}
                        <input
                            class="sr-only"
                            type="file"
                            accept="audio/mpeg,audio/wav,audio/ogg"
                            @change="upload($event, key)"
                        />
                    </label>
                    <button
                        type="button"
                        class="flex min-h-9 items-center gap-1.5 rounded-lg bg-slate-900 px-3 text-xs font-bold text-white transition-colors hover:bg-slate-800 dark:bg-slate-700 dark:hover:bg-slate-600"
                        :aria-label="`Testar som: ${label}`"
                        @click="
                            sound
                                .unlock(sounds)
                                .then(() => sound.play(sounds, key))
                        "
                    >
                        <Volume2 class="size-4" />
                        Testar
                    </button>
                </div>
            </div>
        </div>

        <button
            v-if="roster"
            type="button"
            class="min-h-14 w-full rounded-xl bg-[var(--team-primary)] text-lg font-extrabold text-[var(--team-primary-text)] shadow-md transition-all hover:brightness-110 active:scale-[0.99]"
            data-test="abrir-placar"
            @click="prepare"
        >
            Abrir placar
        </button>
    </section>

    <template v-if="!showConfig && roster">
        <section
            class="relative overflow-hidden border border-white/10 bg-slate-950 text-white shadow-2xl"
            :class="
                isFullscreen
                    ? 'fixed inset-0 z-[45] flex flex-col'
                    : 'rounded-3xl'
            "
            data-test="placar"
        >
            <div
                class="pointer-events-none absolute inset-y-0 left-0 w-1/3 opacity-20"
                :style="{
                    background: `radial-gradient(circle at 0% 50%, ${roster.home.color}, transparent 75%)`,
                }"
            />
            <div
                class="pointer-events-none absolute inset-y-0 right-0 w-1/3 opacity-20"
                :style="{
                    background: `radial-gradient(circle at 100% 50%, ${roster.away.color}, transparent 75%)`,
                }"
            />

            <div
                class="relative flex flex-wrap items-center justify-between gap-2 border-b border-white/10 bg-black/25 px-3 py-2 sm:px-4"
            >
                <div class="flex items-center gap-2">
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-extrabold tracking-wider uppercase"
                        :class="
                            clock.state.phase === 'period' &&
                            !clock.state.paused
                                ? 'bg-emerald-500/20 text-emerald-300'
                                : clock.state.phase === 'rest'
                                  ? 'bg-sky-500/20 text-sky-300'
                                  : clock.state.phase === 'countdown'
                                    ? 'bg-amber-400/20 text-amber-300'
                                    : 'bg-white/10 text-slate-300'
                        "
                    >
                        <span
                            v-if="
                                clock.state.phase === 'period' &&
                                !clock.state.paused
                            "
                            class="size-1.5 animate-pulse rounded-full bg-emerald-400"
                        />
                        {{ phaseLabel }}
                    </span>
                    <span
                        v-if="
                            clock.state.paused && clock.state.phase !== 'idle'
                        "
                        class="rounded-full bg-amber-400/20 px-2 py-0.5 text-[10px] font-extrabold tracking-wider text-amber-300 uppercase"
                    >
                        Pausado
                    </span>
                </div>

                <div class="flex items-center gap-1">
                    <button
                        type="button"
                        class="flex min-h-9 items-center gap-1.5 rounded-lg border border-amber-400/35 bg-amber-400/15 px-2.5 text-xs font-extrabold text-amber-200 transition-all hover:bg-amber-400/25 active:scale-95"
                        :class="{ 'scale-95 bg-amber-400/40': whistlePulse }"
                        title="Tocar apito manual (tecla W)"
                        data-test="apito-manual"
                        @click="blowWhistle"
                    >
                        <Megaphone class="size-3.5" />
                        <span>Apito</span>
                    </button>
                    <button
                        type="button"
                        class="flex min-h-9 items-center gap-1 rounded-lg px-2 text-xs font-semibold text-slate-300 transition-colors hover:bg-white/10 hover:text-white"
                        title="Inverter lados das equipes"
                        aria-label="Inverter lados"
                        @click="swapSides"
                    >
                        <ArrowLeftRight class="size-3.5" />
                    </button>
                    <button
                        type="button"
                        class="flex min-h-9 items-center gap-1 rounded-lg px-2 text-xs font-semibold transition-colors hover:bg-white/10"
                        :class="
                            wake.active.value
                                ? 'text-emerald-300'
                                : 'text-slate-400 hover:text-white'
                        "
                        title="Manter tela acesa"
                        :aria-pressed="wake.active.value"
                        @click="
                            wake.active.value ? wake.release() : wake.request()
                        "
                    >
                        <Clock3 class="size-3.5" />
                        <span class="hidden sm:inline"
                            >Tela
                            {{ wake.active.value ? 'acesa' : 'normal' }}</span
                        >
                    </button>
                    <button
                        type="button"
                        class="flex min-h-9 items-center gap-1 rounded-lg px-2 text-xs font-semibold text-slate-300 transition-colors hover:bg-white/10 hover:text-white"
                        data-test="tela-cheia"
                        @click="toggleFullscreen"
                    >
                        <template v-if="isFullscreen"
                            ><Shrink class="size-3.5" />Sair da tela
                            cheia</template
                        >
                        <template v-else
                            ><Expand class="size-3.5" />Tela cheia</template
                        >
                    </button>
                </div>
            </div>

            <div class="relative h-1 w-full bg-white/10">
                <div
                    class="h-full transition-all duration-300"
                    :class="
                        isCriticalTime
                            ? 'bg-red-500'
                            : isWarningTime
                              ? 'bg-amber-400'
                              : 'bg-emerald-400'
                    "
                    :style="{ width: `${phaseProgress}%` }"
                />
            </div>

            <div
                v-if="clock.state.phase === 'countdown'"
                class="relative bg-amber-300 px-3 py-2 text-center"
                data-test="contagem-gol"
            >
                <span
                    class="block text-xs font-black tracking-widest text-amber-950 uppercase"
                >
                    {{ countdownLabel }}
                </span>
                <strong
                    class="block font-display text-5xl leading-none font-extrabold text-amber-950 tabular-nums"
                >
                    {{ clock.state.remaining }}
                </strong>
                <span class="text-xs font-bold text-amber-950">
                    {{
                        clock.state.paused
                            ? 'Contagem pausada'
                            : 'Preparem-se para o apito'
                    }}
                </span>
            </div>

            <div
                class="relative grid grid-cols-[1fr_auto_1fr] items-center gap-2 px-3 py-4 sm:gap-6 sm:p-6 landscape-short:gap-1 landscape-short:p-2"
                :class="{ 'flex-1': isFullscreen }"
            >
                <div class="min-w-0 text-center">
                    <span
                        class="mx-auto mb-1.5 block h-1.5 w-12 rounded-full sm:w-20"
                        :style="{ background: roster.home.color }"
                    />
                    <h2
                        class="truncate font-extrabold tracking-tight"
                        :class="
                            isFullscreen
                                ? 'text-[min(6vh,4vw)]'
                                : 'text-sm sm:text-xl'
                        "
                    >
                        {{ roster.home.name }}
                    </h2>
                    <strong
                        class="block font-display font-extrabold tabular-nums"
                        :class="[
                            isFullscreen
                                ? 'text-[min(40vh,22vw)] leading-none'
                                : 'text-7xl leading-none sm:text-9xl',
                            lastScoredSide === 'home'
                                ? 'animate-score-pop text-emerald-300'
                                : '',
                        ]"
                        data-test="placar-casa"
                        >{{ homeScore }}</strong
                    >
                </div>

                <div class="text-center">
                    <p
                        class="font-display font-extrabold tracking-tight tabular-nums transition-colors"
                        :class="[
                            isFullscreen
                                ? 'text-[min(24vh,14vw)] leading-none'
                                : 'text-5xl leading-none sm:text-7xl',
                            isCriticalTime
                                ? 'text-red-400'
                                : isWarningTime
                                  ? 'text-amber-300'
                                  : 'text-amber-300',
                        ]"
                        data-test="cronometro"
                    >
                        {{ formattedTime }}
                    </p>
                    <span
                        class="mt-1 inline-block text-[11px] font-semibold text-slate-400"
                        >{{ config.minutes }} min · {{ config.periods }}x</span
                    >
                </div>

                <div class="min-w-0 text-center">
                    <span
                        class="mx-auto mb-1.5 block h-1.5 w-12 rounded-full sm:w-20"
                        :style="{ background: roster.away.color }"
                    />
                    <h2
                        class="truncate font-extrabold tracking-tight"
                        :class="
                            isFullscreen
                                ? 'text-[min(6vh,4vw)]'
                                : 'text-sm sm:text-xl'
                        "
                    >
                        {{ roster.away.name }}
                    </h2>
                    <strong
                        class="block font-display font-extrabold tabular-nums"
                        :class="[
                            isFullscreen
                                ? 'text-[min(40vh,22vw)] leading-none'
                                : 'text-7xl leading-none sm:text-9xl',
                            lastScoredSide === 'away'
                                ? 'animate-score-pop text-emerald-300'
                                : '',
                        ]"
                        data-test="placar-fora"
                        >{{ awayScore }}</strong
                    >
                </div>
            </div>

            <div
                v-if="lastGoal"
                class="relative mx-3 mb-2 flex items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-slate-300"
            >
                <span
                    class="size-2 shrink-0 rounded-full"
                    :style="{ background: roster[lastGoal.team].color }"
                />
                <span class="truncate">
                    Último gol:
                    <strong class="text-white">{{
                        lastGoal.scorer_name
                    }}</strong>
                    <template v-if="lastGoal.assist_name">
                        (assist. {{ lastGoal.assist_name }})
                    </template>
                    · {{ lastGoal.period }}ºT
                    {{ formatClock(lastGoal.remaining_seconds) }}
                </span>
            </div>

            <div class="relative grid grid-cols-2 gap-3 p-3">
                <button
                    type="button"
                    class="min-h-16 rounded-2xl text-xl font-black shadow-lg transition-all hover:brightness-110 active:scale-[0.98] disabled:opacity-30 sm:min-h-20 sm:text-2xl"
                    :style="{
                        background: roster.home.color,
                        color: readableColor(roster.home.color),
                    }"
                    :disabled="disabledGoals"
                    data-test="gol-casa"
                    @click="openGoal('home')"
                >
                    + GOL
                </button>
                <button
                    type="button"
                    class="min-h-16 rounded-2xl text-xl font-black shadow-lg transition-all hover:brightness-110 active:scale-[0.98] disabled:opacity-30 sm:min-h-20 sm:text-2xl"
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

            <div class="relative flex flex-wrap gap-2 px-3 pb-3">
                <button
                    v-if="clock.state.phase !== 'finished'"
                    type="button"
                    class="flex min-h-12 flex-1 items-center justify-center gap-2 rounded-xl bg-[var(--team-primary)] px-4 font-extrabold text-[var(--team-primary-text)] shadow-md transition-all hover:brightness-110 active:scale-[0.98]"
                    data-test="iniciar-cronometro"
                    @click="toggleMatch"
                >
                    <Pause
                        v-if="clock.running && !clock.state.paused"
                        class="size-5"
                    />
                    <Play v-else class="size-5" />
                    {{
                        clock.state.phase === 'idle'
                            ? 'Iniciar partida'
                            : clock.state.phase === 'countdown'
                              ? clock.state.paused
                                  ? 'Continuar contagem'
                                  : 'Pausar contagem'
                              : clock.state.paused
                                ? 'Continuar'
                                : 'Pausar'
                    }}
                </button>

                <button
                    v-if="
                        clock.state.phase === 'period' &&
                        config.countdown_seconds > 0
                    "
                    type="button"
                    class="flex min-h-12 items-center justify-center gap-2 rounded-xl border border-amber-400/50 bg-amber-400/15 px-3 text-sm font-bold text-amber-300 transition-colors hover:bg-amber-400/25"
                    data-test="reiniciar-com-contagem"
                    @click="restartWithCountdown"
                >
                    <Timer class="size-4 shrink-0" />
                    Reiniciar com contagem
                </button>

                <button
                    type="button"
                    class="flex min-h-12 items-center gap-2 rounded-xl border border-white/20 bg-white/5 px-3 text-sm font-bold text-white transition-colors hover:bg-white/10 disabled:opacity-35"
                    :disabled="!goals.length"
                    @click="undo"
                >
                    <Undo2 class="size-4" />
                    Desfazer gol
                </button>

                <button
                    type="button"
                    class="flex size-12 items-center justify-center rounded-xl border border-red-400/35 bg-red-500/10 text-red-300 transition-colors hover:bg-red-500/25"
                    aria-label="Reiniciar"
                    title="Reiniciar partida"
                    @click="resetMatch()"
                >
                    <RotateCcw class="size-4" />
                </button>
            </div>
        </section>

        <div v-if="!isFullscreen" class="mt-3 flex items-center gap-2">
            <button
                type="button"
                class="surface-card flex min-h-12 flex-1 items-center justify-center gap-2 rounded-2xl text-sm font-extrabold text-slate-900 transition-all hover:bg-slate-50 active:scale-[0.99] disabled:opacity-50 dark:text-white dark:hover:bg-slate-800"
                :disabled="saving || queuedForCurrentMatch"
                @click="
                    clock.state.phase === 'finished' ? save() : finishAndSave()
                "
            >
                <Save class="size-4.5 text-[var(--team-primary)]" />
                Salvar partida
            </button>
        </div>

        <p
            v-if="!isFullscreen"
            class="mt-2 hidden text-center text-[11px] font-medium text-slate-400 md:block"
        >
            Atalhos:
            <kbd
                class="rounded bg-slate-200 px-1.5 py-0.5 font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-300"
                >Espaço</kbd
            >
            pausar/retomar ·
            <kbd
                class="rounded bg-slate-200 px-1.5 py-0.5 font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-300"
                >W</kbd
            >
            apito ·
            <kbd
                class="rounded bg-slate-200 px-1.5 py-0.5 font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-300"
                >F</kbd
            >
            tela cheia
        </p>

        <div
            v-if="queued"
            class="mt-3 flex items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm font-medium text-amber-900 dark:border-amber-800/60 dark:bg-amber-950/40 dark:text-amber-100"
        >
            <button
                type="button"
                class="text-left font-bold underline decoration-dotted underline-offset-4"
                @click="openPendingMatchesPanel"
            >
                {{ queued }} partida(s) aguardando envio.
            </button>
            <button
                type="button"
                class="font-bold underline"
                @click="syncNow()"
            >
                Enviar agora
            </button>
        </div>

        <p
            v-if="offlineStatus.error"
            class="mt-2 text-sm font-medium text-red-700 dark:text-red-300"
        >
            {{ offlineStatus.error }} A partida continua aguardando envio.
        </p>

        <section
            v-if="!isFullscreen"
            class="surface-card mt-4 rounded-2xl p-4 sm:p-5"
        >
            <div class="flex items-center justify-between">
                <h2
                    class="text-base font-extrabold text-slate-950 dark:text-white"
                >
                    Gols marcados
                </h2>
                <span
                    class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300"
                >
                    {{ goals.length }}
                </span>
            </div>
            <p
                v-if="!goals.length"
                class="mt-3 text-sm text-slate-500 dark:text-slate-400"
            >
                Os gols aparecem aqui com autor, assistência e tempo.
            </p>
            <div class="mt-3 space-y-2.5">
                <div
                    v-for="goal in [...goals].reverse()"
                    :key="goal.id"
                    class="flex items-center gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50"
                >
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl font-display text-base font-extrabold shadow-xs"
                        :style="{
                            background: roster[goal.team].color,
                            color: readableColor(roster[goal.team].color),
                        }"
                    >
                        {{ goal.period }}º
                    </span>
                    <div class="min-w-0 flex-1">
                        <p
                            class="truncate font-bold text-slate-950 dark:text-white"
                        >
                            {{ goal.scorer_name }}
                        </p>
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
                    <span
                        class="font-display text-lg font-extrabold text-slate-700 tabular-nums dark:text-slate-200"
                    >
                        {{ formatClock(goal.remaining_seconds) }}
                    </span>
                </div>
            </div>
        </section>
    </template>

    <Teleport to="body">
        <div
            v-if="dialog"
            class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/85 backdrop-blur-xs sm:items-center sm:p-6"
            role="dialog"
            aria-modal="true"
            @click.self="dialog = null"
        >
            <div
                class="animate-toast-in surface-card max-h-[90svh] w-full max-w-3xl overflow-y-auto rounded-t-3xl p-5 sm:rounded-3xl sm:p-6"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div
                            v-if="roster"
                            class="mb-1 inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-extrabold"
                            :style="{
                                background: roster[dialog.team].color,
                                color: readableColor(roster[dialog.team].color),
                            }"
                        >
                            {{ roster[dialog.team].name }}
                        </div>
                        <h2
                            class="text-2xl font-extrabold text-slate-950 dark:text-white"
                        >
                            {{
                                dialog.scorer
                                    ? 'Quem deu a assistência?'
                                    : 'Quem fez o gol?'
                            }}
                        </h2>
                        <div
                            v-if="dialog.scorer"
                            class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500 dark:text-slate-400"
                        >
                            <span>
                                Gol de
                                <strong class="text-slate-900 dark:text-white"
                                    >#{{ dialog.scorer.jersey_number }}
                                    {{ dialog.scorer.name }}</strong
                                >
                            </span>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200"
                                @click="dialog.scorer = null"
                            >
                                <ArrowLeft class="size-3" />
                                Trocar autor
                            </button>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="rounded-xl p-2 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800 dark:hover:text-white"
                        aria-label="Cancelar lançamento"
                        @click="dialog = null"
                    >
                        <X class="size-5" />
                    </button>
                </div>

                <div
                    v-if="!dialog.scorer"
                    class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3"
                >
                    <button
                        v-for="player in currentSide"
                        :key="player.id"
                        type="button"
                        class="flex min-h-22 cursor-pointer items-center gap-3 rounded-2xl border-2 border-slate-200 bg-white p-3 text-left font-bold transition-all hover:border-[var(--team-primary)] hover:shadow-sm active:scale-[0.98] dark:border-slate-700 dark:bg-slate-900 dark:hover:border-[var(--team-primary)]"
                        :data-scorer-id="player.id"
                        @click="scorer(player)"
                    >
                        <PlayerPhoto :player="player" size="size-13" />
                        <span class="min-w-0">
                            <strong
                                class="block font-display text-2xl leading-none font-extrabold text-slate-950 dark:text-white"
                                >#{{ player.jersey_number }}</strong
                            >
                            <span
                                class="mt-0.5 block truncate text-sm text-slate-700 dark:text-slate-200"
                                >{{ player.name }}</span
                            >
                        </span>
                    </button>
                </div>

                <div v-else class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <button
                        type="button"
                        class="col-span-full flex min-h-14 items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-slate-300 bg-slate-50 font-extrabold text-slate-700 transition-colors hover:border-slate-400 hover:bg-slate-100 dark:border-slate-600 dark:bg-slate-800/50 dark:text-slate-200"
                        data-test="sem-assistencia"
                        @click="assist(null)"
                    >
                        Sem assistência
                    </button>
                    <button
                        v-for="player in currentSide.filter(
                            (p) => p.id !== dialog?.scorer?.id,
                        )"
                        :key="player.id"
                        type="button"
                        class="flex min-h-22 items-center gap-3 rounded-2xl border-2 border-slate-200 bg-white p-3 text-left font-bold transition-all hover:border-[var(--team-primary)] hover:shadow-sm active:scale-[0.98] dark:border-slate-700 dark:bg-slate-900 dark:hover:border-[var(--team-primary)]"
                        :data-assist-id="player.id"
                        @click="assist(player)"
                    >
                        <PlayerPhoto :player="player" size="size-13" />
                        <span class="min-w-0">
                            <strong
                                class="block font-display text-2xl leading-none font-extrabold text-slate-950 dark:text-white"
                                >#{{ player.jersey_number }}</strong
                            >
                            <span
                                class="mt-0.5 block truncate text-sm text-slate-700 dark:text-slate-200"
                                >{{ player.name }}</span
                            >
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
