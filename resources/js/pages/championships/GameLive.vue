<script setup lang="ts">
import ChampionshipTeamBadge from '@/components/championships/ChampionshipTeamBadge.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    Award,
    Calendar,
    CheckCircle2,
    Clock3,
    Expand,
    Flag,
    MapPin,
    Pause,
    Play,
    RotateCcw,
    Settings2,
    Shrink,
    Target,
    Timer,
    Trash2,
    Trophy,
    Volume2,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useMatchAudio } from '@/composables/useMatchAudio';
import { useMatchClock } from '@/composables/useMatchClock';
import { useWakeLock } from '@/composables/useWakeLock';
import { formatClock } from '@/lib/matchClock';
import { transitionSounds } from '@/lib/matchClockCore';
import type {
    ChampionshipGameEventProp,
    ChampionshipLiveGamePageProps,
    EnrolledPlayerProp,
    GameStatus,
    TeamSide,
} from '@/types/championship';
import type { MatchSettings, SoundSettings } from '@/types/hockey';

const props = defineProps<ChampionshipLiveGamePageProps>();

const page = usePage();
const pageErrors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const flash = computed(
    () => page.props.flash as { success?: string } | undefined,
);

const clockStorageKey = computed(() => `campeonato-relogio:${props.game.id}`);

const config = ref<MatchSettings>({
    minutes: props.clock_settings.minutes,
    periods: props.clock_settings.periods,
    rest_seconds: props.clock_settings.rest_seconds,
    countdown_seconds: props.clock_settings.countdown_seconds,
});

const sounds = ref<SoundSettings>(
    JSON.parse(JSON.stringify(props.sounds)) as SoundSettings,
);

const isOvertimeMode = ref<boolean>(Boolean(props.game.went_to_overtime));
const showAudioSettings = ref(false);
const localError = ref('');
const submitting = ref(false);

let recordedTransitions = 0;
const restoring = ref(true);

const wake = useWakeLock();
const sound = useMatchAudio();
const clock = useMatchClock(
    config.value,
    () => recordTransitions(),
    () => sound.countdown(),
);

const maxAssists = computed(
    () =>
        props.max_assists ??
        props.game.max_assists ??
        props.championship.rules.max_assists_per_goal ??
        2,
);

const overtimeMinutes = computed(
    () =>
        props.overtime_minutes ||
        props.game.overtime_minutes ||
        props.championship.rules.overtime_minutes ||
        5,
);

const homeTeam = computed(() => props.teams.home ?? props.game.home_team);
const awayTeam = computed(() => props.teams.away ?? props.game.away_team);

const homeRoster = computed<EnrolledPlayerProp[]>(
    () => props.rosters.home ?? homeTeam.value?.players ?? [],
);
const awayRoster = computed<EnrolledPlayerProp[]>(
    () => props.rosters.away ?? awayTeam.value?.players ?? [],
);

const bothTeamsDefined = computed(
    () => props.game.home_team_id !== null && props.game.away_team_id !== null,
);

const isKnockout = computed(() => props.game.phase !== 'group');

const canUseOvertime = computed(() => {
    if (isKnockout.value) {
        return (
            props.championship.rules.knockout_tie_resolution ===
            'overtime_then_shootout'
        );
    }
    return Boolean(props.championship.rules.allow_group_overtime_or_shootout);
});

const canUseShootout = computed(() => {
    if (isKnockout.value) return true;
    return Boolean(props.championship.rules.allow_group_overtime_or_shootout);
});

const isRegularScoreTied = computed(
    () => props.game.home_regular_score === props.game.away_regular_score,
);

const requiresWinnerToFinish = computed(
    () =>
        isKnockout.value ||
        Boolean(props.championship.rules.allow_group_overtime_or_shootout),
);

const goalEvents = computed<ChampionshipGameEventProp[]>(() =>
    props.events.filter((e) => e.type === 'goal'),
);

const shootoutEvents = computed<ChampionshipGameEventProp[]>(() =>
    props.events.filter((e) => e.type === 'shootout_attempt'),
);

const activeEventsDesc = computed<ChampionshipGameEventProp[]>(() =>
    [...props.events]
        .filter((e) => e.type === 'goal' || e.type === 'shootout_attempt')
        .reverse(),
);

const formattedTime = computed(() => formatClock(clock.state.remaining));

const phaseLabel = computed(() => {
    if (props.game.status === 'finished') {
        return 'Partida encerrada';
    }
    if (props.game.status === 'scheduled') {
        return 'Aguardando início';
    }
    if (isOvertimeMode.value) {
        if (clock.state.phase === 'countdown') {
            return 'Contagem para a prorrogação';
        }
        if (clock.state.phase === 'period') {
            return 'Prorrogação em andamento';
        }
        if (clock.state.phase === 'finished') {
            return 'Fim da prorrogação';
        }
        return 'Prorrogação pronta';
    }
    return {
        idle: 'Partida ao vivo · Relógio pronto',
        countdown:
            clock.state.period > 1
                ? `Preparem-se para o ${clock.state.period}º tempo!`
                : 'Preparem-se!',
        period: `${clock.state.period}º tempo`,
        rest: 'Intervalo / Descanso',
        finished: 'Tempo regulamentar encerrado',
    }[clock.state.phase];
});

const statusLabels: Record<GameStatus, string> = {
    scheduled: 'Agendado',
    live: 'Ao vivo',
    finished: 'Encerrado',
};

const statusClasses: Record<GameStatus, string> = {
    scheduled:
        'bg-slate-200 text-slate-800 dark:bg-slate-700 dark:text-slate-100',
    live: 'bg-red-500 text-white animate-pulse',
    finished: 'bg-emerald-500 text-white',
};

function readableColor(hex: string | null | undefined): string {
    if (!hex) return '#ffffff';
    const lower = hex.toLowerCase();
    return lower === '#f59e0b' ||
        lower === '#84cc16' ||
        lower === '#eab308' ||
        lower === '#ffffff' ||
        lower === '#f8fafc'
        ? '#111827'
        : '#ffffff';
}

function saveClockToStorage() {
    if (
        restoring.value ||
        props.game.status === 'finished' ||
        clock.state.phase === 'idle'
    ) {
        return;
    }
    try {
        localStorage.setItem(
            clockStorageKey.value,
            JSON.stringify({
                config: config.value,
                isOvertimeMode: isOvertimeMode.value,
                serializedClock: clock.export(),
            }),
        );
    } catch {
        /* Armazenamento local indisponível. */
    }
}

function clearClockStorage() {
    try {
        localStorage.removeItem(clockStorageKey.value);
    } catch {
        /* Ignora erro de localStorage. */
    }
}

function recordTransitions() {
    for (const event of clock.transitions.slice(recordedTransitions)) {
        if (!restoring.value)
            for (const soundName of transitionSounds(event))
                sound.play(sounds.value, soundName);
        if (event.started === 'finished') {
            void wake.release();
        }
    }
    recordedTransitions = clock.transitions.length;
    saveClockToStorage();
}

onMounted(() => {
    try {
        interface StoredChampionshipClock {
            config?: MatchSettings;
            isOvertimeMode?: boolean;
            serializedClock?: string;
        }
        const saved = JSON.parse(
            localStorage.getItem(clockStorageKey.value) ?? 'null',
        ) as StoredChampionshipClock | null;

        if (
            saved &&
            saved.serializedClock &&
            props.game.status !== 'finished'
        ) {
            if (typeof saved.isOvertimeMode === 'boolean') {
                isOvertimeMode.value = saved.isOvertimeMode;
            }
            if (saved.config) {
                Object.assign(config.value, saved.config);
                clock.configure(config.value);
            }
            const parsedClock = JSON.parse(saved.serializedClock) as {
                transitions?: unknown[];
            } | null;
            recordedTransitions = Array.isArray(parsedClock?.transitions)
                ? parsedClock.transitions.length
                : 0;
            clock.restore(saved.serializedClock);
            clock.configure(config.value);
            recordTransitions();
        }
    } catch {
        clearClockStorage();
    }
    restoring.value = false;
});

watch(
    () => clock.running,
    (running) => {
        if (running && !clock.state.paused) {
            void wake.request();
        }
    },
);

watch(
    [
        () => clock.state.phase,
        () => clock.state.period,
        () => clock.state.paused,
        isOvertimeMode,
        config,
    ],
    () => {
        saveClockToStorage();
    },
    { deep: true },
);

const onPageHide = () => {
    saveClockToStorage();
};

onMounted(() => window.addEventListener('pagehide', onPageHide));
onBeforeUnmount(() => {
    window.removeEventListener('pagehide', onPageHide);
    void wake.release();
});

// Fullscreen control
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
        if (document.fullscreenElement) {
            await document.exitFullscreen();
        } else {
            await document.documentElement.requestFullscreen();
            if ('orientation' in screen) {
                await (
                    screen.orientation as ScreenOrientation & {
                        lock?: (orientation: string) => Promise<void>;
                    }
                )
                    .lock?.('landscape')
                    .catch(() => undefined);
            }
        }
    } catch {
        /* O navegador pode bloquear tela cheia ou rotação. */
    }
}

function uploadSound(event: Event, key: 'start' | 'end' | 'rest_end') {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (
        !file ||
        typeof file.type !== 'string' ||
        !['audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/ogg'].includes(
            file.type,
        )
    ) {
        localError.value = 'Escolha um áudio MP3, WAV ou OGG.';
        return;
    }
    if (file.size > 4 * 1024 * 1024) {
        localError.value = 'O som deve ter no máximo 4 MB.';
        return;
    }
    localError.value = '';
    sounds.value[key] = {
        type: 'custom',
        file: URL.createObjectURL(file),
        name: file.name,
    };
}

// Clock Actions
async function toggleClock() {
    await sound.unlock(sounds.value);
    if (clock.state.phase === 'idle') {
        clock.configure(config.value);
        clock.start();
        void wake.request();
        if ((clock.state.phase as string) !== 'countdown') {
            sound.play(sounds.value, 'start');
        }
    } else if (clock.state.phase === 'finished') {
        clock.reset();
        clock.configure(config.value);
        clock.start();
        void wake.request();
        if ((clock.state.phase as string) !== 'countdown') {
            sound.play(sounds.value, 'start');
        }
    } else {
        clock.togglePause();
    }
    saveClockToStorage();
}

function resetClock() {
    if (
        clock.state.phase !== 'idle' &&
        !window.confirm('Reiniciar o cronômetro local deste período?')
    ) {
        return;
    }
    isOvertimeMode.value = false;
    config.value = {
        minutes: props.clock_settings.minutes,
        periods: props.clock_settings.periods,
        rest_seconds: props.clock_settings.rest_seconds,
        countdown_seconds: props.clock_settings.countdown_seconds,
    };
    clock.configure(config.value);
    clock.reset();
    recordedTransitions = 0;
    clearClockStorage();
    void wake.release();
}

async function startOvertimeClock() {
    if (!canUseOvertime.value) return;
    await sound.unlock(sounds.value);
    isOvertimeMode.value = true;
    config.value = {
        minutes: overtimeMinutes.value,
        periods: 1,
        rest_seconds: 0,
        countdown_seconds: props.clock_settings.countdown_seconds,
    };
    clock.reset();
    recordedTransitions = 0;
    clock.configure(config.value);
    clock.start();
    void wake.request();
    if ((clock.state.phase as string) !== 'countdown') {
        sound.play(sounds.value, 'start');
    }
    saveClockToStorage();
}

// 1. Start Game (scheduled -> live)
const showStartConfirm = ref(false);

function confirmStartGame() {
    if (submitting.value) return;
    void sound.unlock(sounds.value);
    submitting.value = true;
    localError.value = '';
    router.post(
        `/campeonatos/${props.championship.id}/jogos/${props.game.id}/iniciar`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                showStartConfirm.value = false;
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

// 2. Goal Modal (pick scorer -> pick up to maxAssists assists or 'Sem assistência')
interface GoalDialogState {
    side: TeamSide;
    scorer: EnrolledPlayerProp | null;
    firstAssist: EnrolledPlayerProp | null;
    secondAssist: EnrolledPlayerProp | null;
    period: number;
    isOvertime: boolean;
}

const goalDialog = ref<GoalDialogState | null>(null);

const activeDialogTeam = computed(() => {
    if (!goalDialog.value) return null;
    return goalDialog.value.side === 'home' ? homeTeam.value : awayTeam.value;
});

const activeDialogRoster = computed<EnrolledPlayerProp[]>(() => {
    if (!goalDialog.value) return [];
    return goalDialog.value.side === 'home'
        ? homeRoster.value
        : awayRoster.value;
});

const availableFirstAssistPlayers = computed<EnrolledPlayerProp[]>(() => {
    const current = goalDialog.value;
    if (!current?.scorer) return [];
    return activeDialogRoster.value.filter((p) => p.id !== current.scorer?.id);
});

const availableSecondAssistPlayers = computed<EnrolledPlayerProp[]>(() => {
    const current = goalDialog.value;
    if (!current?.scorer || !current?.firstAssist) return [];
    return activeDialogRoster.value.filter(
        (p) => p.id !== current.scorer?.id && p.id !== current.firstAssist?.id,
    );
});

async function openGoalDialog(side: TeamSide) {
    if (props.game.status !== 'live') return;
    await sound.unlock(sounds.value);
    localError.value = '';
    goalDialog.value = {
        side,
        scorer: null,
        firstAssist: null,
        secondAssist: null,
        period: Math.max(1, clock.state.period || 1),
        isOvertime: isOvertimeMode.value,
    };
}

function selectGoalScorer(player: EnrolledPlayerProp) {
    if (!goalDialog.value) return;
    goalDialog.value.scorer = player;
    goalDialog.value.firstAssist = null;
    goalDialog.value.secondAssist = null;
    if (maxAssists.value <= 0) {
        submitGoalRecord([]);
    }
}

function selectFirstAssist(player: EnrolledPlayerProp | null) {
    if (!goalDialog.value || !goalDialog.value.scorer) return;
    if (player === null) {
        submitGoalRecord([]);
        return;
    }
    goalDialog.value.firstAssist = player;
    if (
        maxAssists.value === 1 ||
        availableSecondAssistPlayers.value.length === 0
    ) {
        submitGoalRecord([player.id]);
    }
}

function selectSecondAssist(player: EnrolledPlayerProp | null) {
    if (
        !goalDialog.value ||
        !goalDialog.value.scorer ||
        !goalDialog.value.firstAssist
    ) {
        return;
    }
    if (player === null) {
        submitGoalRecord([goalDialog.value.firstAssist.id]);
        return;
    }
    goalDialog.value.secondAssist = player;
    submitGoalRecord([goalDialog.value.firstAssist.id, player.id]);
}

function submitGoalRecord(assistIds: number[]) {
    const current = goalDialog.value;
    if (!current || !current.scorer || submitting.value) return;

    void sound.unlock(sounds.value);
    submitting.value = true;
    localError.value = '';

    const elapsedSeconds = Math.max(0, clock.state.elapsed || 0);
    const remainingSeconds = Math.max(0, clock.state.remaining || 0);
    const period = Math.max(1, Number(current.period) || 1);
    const isOvertime = Boolean(current.isOvertime);

    router.post(
        `/campeonatos/${props.championship.id}/jogos/${props.game.id}/gols`,
        {
            side: current.side,
            scorer_id: current.scorer.id,
            assist_ids: assistIds,
            first_assist_id: assistIds[0] ?? null,
            second_assist_id: assistIds[1] ?? null,
            period,
            is_overtime: isOvertime,
            overtime: isOvertime,
            elapsed_seconds: elapsedSeconds,
            remaining_seconds: remainingSeconds,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                if (clock.state.phase === 'period' && !clock.state.paused) {
                    clock.restartCountdown();
                    saveClockToStorage();
                }
                goalDialog.value = null;
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

// 3. Shootout Panel
const shootoutSide = ref<TeamSide>('home');
const shootoutShooterId = ref<number | ''>('');
const shootoutScored = ref<boolean>(true);
const shootoutRound = ref<number>(1);

const shootoutRoster = computed<EnrolledPlayerProp[]>(() =>
    shootoutSide.value === 'home' ? homeRoster.value : awayRoster.value,
);

watch(
    shootoutEvents,
    (events) => {
        const homeCount = events.filter((e) => e.side === 'home').length;
        const awayCount = events.filter((e) => e.side === 'away').length;
        shootoutRound.value = Math.max(
            1,
            Math.floor(Math.min(homeCount, awayCount)) + 1,
        );
        if (homeCount > awayCount) {
            shootoutSide.value = 'away';
        } else if (awayCount > homeCount) {
            shootoutSide.value = 'home';
        }
    },
    { immediate: true },
);

watch(shootoutSide, () => {
    shootoutShooterId.value = '';
});

function submitShootoutAttempt() {
    if (
        submitting.value ||
        shootoutShooterId.value === '' ||
        props.game.status !== 'live'
    ) {
        return;
    }
    submitting.value = true;
    localError.value = '';

    router.post(
        `/campeonatos/${props.championship.id}/jogos/${props.game.id}/penaltis`,
        {
            side: shootoutSide.value,
            shooter_id: Number(shootoutShooterId.value),
            scored: Boolean(shootoutScored.value),
            round: Math.max(1, Number(shootoutRound.value) || 1),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                shootoutShooterId.value = '';
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

// 4. Remove Event (confirm + motivo)
const eventToRemove = ref<ChampionshipGameEventProp | null>(null);
const removeEventReason = ref('');

function openRemoveEventModal(event: ChampionshipGameEventProp) {
    eventToRemove.value = event;
    removeEventReason.value = '';
    localError.value = '';
}

function closeRemoveEventModal() {
    eventToRemove.value = null;
    removeEventReason.value = '';
}

function confirmRemoveEvent() {
    const target = eventToRemove.value;
    if (!target || submitting.value) return;

    const trimmedReason = removeEventReason.value.trim();
    if (!trimmedReason) {
        localError.value = 'Informe o motivo para remover o lance da súmula.';
        return;
    }

    submitting.value = true;
    localError.value = '';

    router.delete(
        `/campeonatos/${props.championship.id}/jogos/${props.game.id}/eventos/${target.id}`,
        {
            data: {
                motivo: trimmedReason,
            },
            preserveScroll: true,
            onSuccess: () => {
                closeRemoveEventModal();
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

// 5. Finish Game (confirm + validation display)
const showFinishConfirm = ref(false);

function confirmFinishGame() {
    if (submitting.value) return;
    submitting.value = true;
    localError.value = '';

    router.post(
        `/campeonatos/${props.championship.id}/jogos/${props.game.id}/finalizar`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                clock.finish();
                clearClockStorage();
                void wake.release();
                showFinishConfirm.value = false;
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

// 6. Reopen Game (motivo required + checkbox resetar_chave)
const showReopenModal = ref(false);
const reopenReason = ref('');
const reopenResetBracket = ref(false);

const bracketNeedsResetHint = computed(() => {
    if (props.game.phase !== 'group') return false;
    if (props.championship.bracket_generated_at !== null) return true;
    const err = (pageErrors.value.championship ?? '').toLowerCase();
    return err.includes('chave') || err.includes('mata-mata');
});

function openReopenModal() {
    reopenReason.value = '';
    reopenResetBracket.value = false;
    localError.value = '';
    showReopenModal.value = true;
}

function submitReopenGame() {
    if (submitting.value) return;
    const trimmed = reopenReason.value.trim();
    if (!trimmed) {
        localError.value = 'Informe o motivo para reabrir a partida.';
        return;
    }

    submitting.value = true;
    localError.value = '';

    router.post(
        `/campeonatos/${props.championship.id}/jogos/${props.game.id}/reabrir`,
        {
            motivo: trimmed,
            resetar_chave: reopenResetBracket.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                showReopenModal.value = false;
                reopenReason.value = '';
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

function teamNameForEvent(event: ChampionshipGameEventProp): string {
    if (event.side === 'home') {
        return homeTeam.value?.name ?? props.game.home_team_name ?? 'Casa';
    }
    if (event.side === 'away') {
        return awayTeam.value?.name ?? props.game.away_team_name ?? 'Visitante';
    }
    return 'Equipe';
}

function teamColorForEvent(event: ChampionshipGameEventProp): string {
    if (event.side === 'home') {
        return homeTeam.value?.primary_color ?? '#2563eb';
    }
    if (event.side === 'away') {
        return awayTeam.value?.primary_color ?? '#dc2626';
    }
    return '#334155';
}

function formatDateTime(iso: string | null): string {
    if (!iso) return 'Horário a definir';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <Head
        :title="`${game.home_team_name ?? game.home_source} × ${game.away_team_name ?? game.away_source} · ${championship.name}`"
    />

    <!-- Header bar (hidden in fullscreen) -->
    <div
        v-if="!isFullscreen"
        class="mb-4 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div
                    class="flex flex-wrap items-center gap-3 text-xs font-bold"
                >
                    <Link
                        :href="`/campeonatos/${championship.id}`"
                        class="inline-flex items-center gap-1 text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white"
                        data-test="voltar-campeonato"
                    >
                        <ArrowLeft class="size-3.5" />
                        {{ championship.name }}
                    </Link>
                    <span class="text-slate-300">·</span>
                    <Link
                        :href="`/campeonatos/${championship.id}/resumo`"
                        class="inline-flex items-center gap-1 text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white"
                    >
                        <Award class="size-3.5 text-amber-500" />
                        Resumo e pódio
                    </Link>
                </div>

                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                    <h1
                        class="text-xl font-black text-slate-950 sm:text-2xl dark:text-slate-100"
                    >
                        {{ game.phase_label }}
                        <template v-if="game.group_code">
                            · Grupo {{ game.group_code }}
                        </template>
                        <template v-else> · {{ game.slot_key }} </template>
                    </h1>
                    <span
                        class="rounded-full px-2.5 py-0.5 text-xs font-bold"
                        :class="statusClasses[game.status]"
                    >
                        {{ statusLabels[game.status] }}
                    </span>
                </div>

                <div
                    class="mt-1.5 flex flex-wrap items-center gap-3 text-xs text-slate-500 dark:text-slate-400"
                >
                    <span>Rodada {{ game.round_number }}</span>
                    <span v-if="game.leg_number === 2">· Returno</span>
                    <span
                        v-if="game.venue"
                        class="inline-flex items-center gap-1"
                    >
                        <MapPin class="size-3.5" />
                        {{ game.venue }}
                    </span>
                    <span class="inline-flex items-center gap-1">
                        <Calendar class="size-3.5" />
                        {{ formatDateTime(game.scheduled_at) }}
                    </span>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="flex size-11 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800/60"
                    aria-label="Configurações de áudio e cronômetro"
                    @click="showAudioSettings = !showAudioSettings"
                >
                    <Settings2 class="size-5" />
                </button>
            </div>
        </div>
    </div>

    <!-- Error banners -->
    <div
        v-if="pageErrors.championship || pageErrors.motivo || localError"
        class="mb-4 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800"
        role="alert"
        data-test="erro-partida"
    >
        <AlertTriangle class="mt-0.5 size-5 shrink-0 text-red-600" />
        <div class="space-y-1">
            <p v-if="pageErrors.championship">{{ pageErrors.championship }}</p>
            <p v-if="pageErrors.motivo">{{ pageErrors.motivo }}</p>
            <p v-if="localError">{{ localError }}</p>
        </div>
    </div>

    <!-- Audio settings panel (optional toggle) -->
    <section
        v-if="showAudioSettings && !isFullscreen"
        class="mb-4 space-y-4 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
    >
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-sm font-black">Sons da partida</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Apito no início, buzzer no fim do tempo e bipes nos últimos
                    5 segundos de cada contagem.
                </p>
            </div>
            <button
                type="button"
                class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
                @click="showAudioSettings = false"
            >
                <X class="size-4" />
            </button>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div
                v-for="(label, key) in {
                    start: 'Início de tempo / partida',
                    end: 'Fim de tempo / partida',
                    rest_end: 'Fim do descanso',
                }"
                :key="key"
                class="flex flex-col justify-between gap-2 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50"
            >
                <span
                    class="text-xs font-bold text-slate-800 dark:text-slate-100"
                    >{{ label }}</span
                >
                <div class="flex flex-wrap items-center gap-2">
                    <label
                        class="cursor-pointer rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-bold dark:border-slate-600 dark:bg-slate-900"
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
                            @change="uploadSound($event, key)"
                        />
                    </label>
                    <button
                        type="button"
                        class="inline-flex min-h-8 items-center gap-1 rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs font-bold text-white dark:bg-slate-700"
                        @click="
                            sound
                                .unlock(sounds)
                                .then(() => sound.play(sounds, key))
                        "
                    >
                        <Volume2 class="size-3.5" />
                        Testar
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- Main Scoreboard Section -->
    <section
        class="overflow-hidden bg-slate-950 text-white shadow-2xl"
        :class="
            isFullscreen
                ? 'fixed inset-0 z-[45] flex flex-col justify-between'
                : 'rounded-2xl'
        "
        data-test="placar-campeonato"
    >
        <!-- Top Status & Fullscreen / WakeLock Bar -->
        <div
            class="flex flex-wrap items-center justify-between gap-2 border-b border-white/10 px-3 py-2"
        >
            <div class="flex items-center gap-2">
                <span
                    class="rounded-full px-2 py-0.5 text-[10px] font-black uppercase"
                    :class="statusClasses[game.status]"
                >
                    {{ statusLabels[game.status] }}
                </span>
                <span
                    class="text-xs font-bold tracking-widest text-slate-300 uppercase"
                >
                    {{ phaseLabel }}
                </span>
            </div>

            <div class="flex items-center gap-1">
                <button
                    type="button"
                    class="flex min-h-10 items-center gap-1 px-2 text-xs text-slate-300 hover:text-white"
                    title="Manter tela acesa"
                    :aria-pressed="wake.active.value"
                    @click="wake.active.value ? wake.release() : wake.request()"
                >
                    <Clock3 class="size-4" />
                    <span class="hidden sm:inline">
                        Tela acesa
                        {{ wake.active.value ? 'ativada' : 'desativada' }}
                    </span>
                </button>
                <button
                    type="button"
                    class="flex min-h-10 items-center gap-1 px-2 text-xs text-slate-300 hover:text-white"
                    data-test="tela-cheia"
                    @click="toggleFullscreen"
                >
                    <template v-if="isFullscreen">
                        <Shrink class="size-4" />Sair da tela cheia
                    </template>
                    <template v-else>
                        <Expand class="size-4" />Tela cheia
                    </template>
                </button>
            </div>
        </div>

        <!-- Flash or Error inside Fullscreen -->
        <div
            v-if="isFullscreen && (pageErrors.championship || flash?.success)"
            class="px-4 py-2 text-center text-xs font-bold"
            :class="
                pageErrors.championship
                    ? 'bg-red-600 text-white'
                    : 'bg-emerald-600 text-white'
            "
        >
            {{ pageErrors.championship || flash?.success }}
        </div>

        <!-- Countdown Banner -->
        <div
            v-if="clock.state.phase === 'countdown'"
            class="bg-amber-300 px-3 py-2 text-center"
            data-test="contagem-regressiva"
        >
            <span
                class="block text-xs font-black tracking-widest text-amber-950 uppercase"
            >
                {{
                    goalEvents.length
                        ? 'Recomeço após gol'
                        : 'Preparem-se para o apito'
                }}
            </span>
            <strong
                class="block text-4xl font-black text-amber-950 tabular-nums"
            >
                {{ clock.state.remaining }}
            </strong>
        </div>

        <!-- Teams, Big Score, and Clock -->
        <div
            class="grid grid-cols-[1fr_auto_1fr] items-center gap-2 p-4 sm:gap-6 sm:p-6"
            :class="{ 'flex-1': isFullscreen }"
        >
            <!-- Home Team -->
            <div class="flex flex-col items-center text-center">
                <ChampionshipTeamBadge
                    v-if="homeTeam"
                    :name="homeTeam.name"
                    :short-name="homeTeam.short_name"
                    :logo-url="homeTeam.logo_url"
                    :primary-color="homeTeam.primary_color"
                    :secondary-color="homeTeam.secondary_color"
                    size="lg"
                />
                <h2
                    class="mt-2 max-w-full truncate font-black"
                    :class="
                        isFullscreen
                            ? 'text-[min(5vh,3.5vw)]'
                            : 'text-sm sm:text-xl'
                    "
                >
                    {{
                        homeTeam?.name ??
                        game.home_team_name ??
                        game.home_source
                    }}
                </h2>
                <strong
                    class="mt-1 block font-black tabular-nums"
                    :class="
                        isFullscreen
                            ? 'text-[min(36vh,20vw)] leading-none'
                            : 'text-6xl sm:text-8xl'
                    "
                    data-test="placar-casa"
                >
                    {{ game.home_regular_score }}
                </strong>
                <span
                    v-if="game.went_to_shootout || shootoutEvents.length"
                    class="mt-1 rounded-full bg-white/10 px-2.5 py-0.5 text-xs font-bold text-amber-300"
                    data-test="shootout-placar-casa"
                >
                    Shootout: {{ game.home_shootout_score }}
                </span>
            </div>

            <!-- Center Clock & Period Info -->
            <div class="text-center">
                <span
                    v-if="isOvertimeMode || game.went_to_overtime"
                    class="mb-1 inline-block rounded-md bg-amber-400/20 px-2 py-0.5 text-[11px] font-black tracking-wider text-amber-300 uppercase"
                >
                    Prorrogação
                </span>
                <span
                    v-else
                    class="mb-1 block text-xs font-bold tracking-wider text-slate-400 uppercase"
                >
                    {{ clock.state.period }}º Período
                </span>

                <p
                    class="font-black text-amber-300 tabular-nums"
                    :class="
                        isFullscreen
                            ? 'text-[min(22vh,13vw)] leading-none'
                            : 'text-4xl sm:text-6xl'
                    "
                    data-test="cronometro"
                >
                    {{ formattedTime }}
                </p>

                <span class="mt-1 block text-xs text-slate-400">
                    <template v-if="isOvertimeMode">
                        Prorrogação de {{ overtimeMinutes }} min
                    </template>
                    <template v-else>
                        {{ clock_settings.periods }}×{{
                            clock_settings.minutes
                        }}
                        min · Intervalo {{ clock_settings.rest_seconds }}s
                    </template>
                </span>

                <div
                    v-if="game.winner_team_name"
                    class="mt-2 inline-flex items-center gap-1 rounded-full bg-emerald-500/20 px-3 py-1 text-xs font-bold text-emerald-300"
                >
                    <Trophy class="size-3.5 text-amber-400" />
                    Vencedor: {{ game.winner_team_name }}
                </div>
            </div>

            <!-- Away Team -->
            <div class="flex flex-col items-center text-center">
                <ChampionshipTeamBadge
                    v-if="awayTeam"
                    :name="awayTeam.name"
                    :short-name="awayTeam.short_name"
                    :logo-url="awayTeam.logo_url"
                    :primary-color="awayTeam.primary_color"
                    :secondary-color="awayTeam.secondary_color"
                    size="lg"
                />
                <h2
                    class="mt-2 max-w-full truncate font-black"
                    :class="
                        isFullscreen
                            ? 'text-[min(5vh,3.5vw)]'
                            : 'text-sm sm:text-xl'
                    "
                >
                    {{
                        awayTeam?.name ??
                        game.away_team_name ??
                        game.away_source
                    }}
                </h2>
                <strong
                    class="mt-1 block font-black tabular-nums"
                    :class="
                        isFullscreen
                            ? 'text-[min(36vh,20vw)] leading-none'
                            : 'text-6xl sm:text-8xl'
                    "
                    data-test="placar-fora"
                >
                    {{ game.away_regular_score }}
                </strong>
                <span
                    v-if="game.went_to_shootout || shootoutEvents.length"
                    class="mt-1 rounded-full bg-white/10 px-2.5 py-0.5 text-xs font-bold text-amber-300"
                    data-test="shootout-placar-fora"
                >
                    Shootout: {{ game.away_shootout_score }}
                </span>
            </div>
        </div>

        <!-- Action Controls inside Scoreboard -->
        <!-- Case A: Scheduled -->
        <div
            v-if="game.status === 'scheduled'"
            class="border-t border-white/10 bg-slate-900/90 p-4"
        >
            <div
                v-if="!bothTeamsDefined"
                class="rounded-xl bg-amber-500/20 p-3 text-center text-xs font-bold text-amber-200"
            >
                Aguardando definição das duas equipes neste confronto ({{
                    game.home_source
                }}
                × {{ game.away_source }}) para iniciar a partida.
            </div>
            <div
                v-else
                class="flex flex-wrap items-center justify-between gap-3"
            >
                <p class="text-xs text-slate-300">
                    Inicie oficialmente a partida para liberar o registro de
                    gols, cronômetro e súmula ao vivo.
                </p>
                <button
                    type="button"
                    class="inline-flex min-h-12 items-center gap-2 rounded-xl bg-emerald-600 px-6 text-sm font-black text-white hover:bg-emerald-500 disabled:opacity-50"
                    data-test="iniciar-jogo"
                    :disabled="submitting"
                    @click="showStartConfirm = true"
                >
                    <Play class="size-5" />
                    Iniciar jogo
                </button>
            </div>
        </div>

        <!-- Case B: Live -->
        <div
            v-else-if="game.status === 'live'"
            class="space-y-3 border-t border-white/10 bg-slate-900/80 p-3 sm:p-4"
        >
            <!-- Big Goal Buttons per Team -->
            <div class="grid grid-cols-2 gap-3">
                <button
                    type="button"
                    class="min-h-16 rounded-xl px-3 text-lg font-black transition hover:opacity-95 disabled:opacity-40 sm:min-h-20 sm:text-2xl"
                    :style="{
                        backgroundColor: homeTeam?.primary_color || '#2563eb',
                        color: readableColor(homeTeam?.primary_color),
                    }"
                    :disabled="submitting || !homeRoster.length"
                    data-test="gol-casa"
                    @click="openGoalDialog('home')"
                >
                    + GOL {{ homeTeam?.short_name || homeTeam?.name || 'CASA' }}
                </button>

                <button
                    type="button"
                    class="min-h-16 rounded-xl px-3 text-lg font-black transition hover:opacity-95 disabled:opacity-40 sm:min-h-20 sm:text-2xl"
                    :style="{
                        backgroundColor: awayTeam?.primary_color || '#dc2626',
                        color: readableColor(awayTeam?.primary_color),
                    }"
                    :disabled="submitting || !awayRoster.length"
                    data-test="gol-fora"
                    @click="openGoalDialog('away')"
                >
                    + GOL
                    {{ awayTeam?.short_name || awayTeam?.name || 'VISITANTE' }}
                </button>
            </div>

            <!-- Clock + Overtime + Finish Controls -->
            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="flex min-h-12 flex-1 items-center justify-center gap-2 rounded-xl bg-[var(--team-primary)] px-4 text-sm font-bold text-white"
                    data-test="iniciar-cronometro"
                    @click="toggleClock"
                >
                    <Pause v-if="clock.running && !clock.state.paused" />
                    <Play v-else />
                    <span>
                        {{
                            clock.state.phase === 'idle'
                                ? 'Iniciar cronômetro'
                                : clock.state.phase === 'finished'
                                  ? 'Reiniciar período'
                                  : clock.state.paused
                                    ? 'Continuar cronômetro'
                                    : 'Pausar cronômetro'
                        }}
                    </span>
                </button>

                <button
                    v-if="canUseOvertime"
                    type="button"
                    class="flex min-h-12 items-center gap-1.5 rounded-xl border px-3.5 text-xs font-bold transition sm:text-sm"
                    :class="
                        isOvertimeMode
                            ? 'border-amber-400 bg-amber-400 text-slate-950 dark:text-slate-100'
                            : 'border-amber-400/50 bg-amber-400/10 text-amber-300 hover:bg-amber-400/20'
                    "
                    data-test="iniciar-prorrogacao"
                    @click="startOvertimeClock"
                >
                    <Timer class="size-4" />
                    Prorrogação ({{ overtimeMinutes }} min)
                </button>

                <button
                    type="button"
                    class="flex size-12 items-center justify-center rounded-xl border border-white/20 text-slate-300 hover:bg-white/10"
                    aria-label="Reiniciar cronômetro"
                    title="Reiniciar cronômetro"
                    @click="resetClock"
                >
                    <RotateCcw class="size-4" />
                </button>

                <button
                    type="button"
                    class="flex min-h-12 items-center gap-2 rounded-xl bg-emerald-600 px-4 text-sm font-black text-white hover:bg-emerald-500 disabled:opacity-50"
                    data-test="finalizar-jogo"
                    :disabled="submitting"
                    @click="showFinishConfirm = true"
                >
                    <Flag class="size-4" />
                    Finalizar
                </button>
            </div>
        </div>

        <!-- Case C: Finished -->
        <div
            v-else-if="game.status === 'finished'"
            class="flex flex-wrap items-center justify-between gap-3 border-t border-white/10 bg-slate-900/90 p-4"
        >
            <div class="flex items-center gap-2 text-xs text-emerald-300">
                <CheckCircle2 class="size-4 shrink-0" />
                <span>
                    Partida finalizada
                    <template v-if="game.winner_team_name">
                        · Vitória de
                        <strong>{{ game.winner_team_name }}</strong>
                    </template>
                    <template v-else> · Empate registrado </template>
                </span>
            </div>

            <button
                v-if="!game.is_bye"
                type="button"
                class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-amber-400/50 bg-amber-400/10 px-4 text-xs font-bold text-amber-300 hover:bg-amber-400/20"
                data-test="abrir-reabrir-jogo"
                @click="openReopenModal"
            >
                <RotateCcw class="size-4" />
                Reabrir partida para retificação
            </button>
        </div>
    </section>

    <!-- Below Scoreboard Sections (hidden in fullscreen) -->
    <div v-if="!isFullscreen" class="mt-5 grid gap-5 lg:grid-cols-2">
        <!-- Shootout / Pênaltis Panel (shown separately when allowed) -->
        <section
            v-if="
                canUseShootout &&
                (game.status === 'live' ||
                    game.went_to_shootout ||
                    shootoutEvents.length > 0)
            "
            class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 dark:border-slate-700 dark:bg-slate-900"
            data-test="painel-shootout"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <Target class="size-5 text-purple-600" />
                    <div>
                        <h2
                            class="text-base font-black text-slate-900 dark:text-slate-100"
                        >
                            Disputa de Shootout / Pênaltis
                        </h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Placar separado — cobranças de shootout nunca somam
                            na artilharia nem no placar regular.
                        </p>
                    </div>
                </div>

                <span
                    class="rounded-xl bg-purple-100 px-3 py-1 text-sm font-black text-purple-900"
                >
                    {{ game.home_shootout_score }} ×
                    {{ game.away_shootout_score }}
                </span>
            </div>

            <div
                v-if="game.status === 'live' && !isRegularScoreTied"
                class="mt-3 rounded-xl bg-slate-50 p-3 text-xs text-slate-600 dark:bg-slate-800/50 dark:text-slate-300"
            >
                O registro de cobranças de shootout fica disponível quando o
                placar do tempo normal/prorrogação está empatado (atual:
                {{ game.home_regular_score }} × {{ game.away_regular_score }}).
            </div>

            <form
                v-if="game.status === 'live' && isRegularScoreTied"
                class="mt-4 space-y-3 rounded-xl border border-slate-200 bg-slate-50/70 p-3.5 dark:border-slate-700 dark:bg-slate-800/50"
                @submit.prevent="submitShootoutAttempt"
            >
                <div class="grid gap-3 sm:grid-cols-2">
                    <label
                        class="block text-xs font-bold text-slate-700 dark:text-slate-200"
                    >
                        Equipe cobradora
                        <select
                            v-model="shootoutSide"
                            name="shootout_side"
                            class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm font-semibold dark:border-slate-600 dark:bg-slate-900"
                            data-test="shootout-equipe"
                        >
                            <option value="home">
                                {{ homeTeam?.name ?? 'Casa' }}
                            </option>
                            <option value="away">
                                {{ awayTeam?.name ?? 'Visitante' }}
                            </option>
                        </select>
                    </label>

                    <label
                        class="block text-xs font-bold text-slate-700 dark:text-slate-200"
                    >
                        Rodada
                        <input
                            v-model.number="shootoutRound"
                            name="shootout_round"
                            type="number"
                            min="1"
                            max="50"
                            required
                            class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm font-semibold dark:border-slate-600 dark:bg-slate-900"
                            data-test="shootout-rodada"
                        />
                    </label>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label
                        class="block text-xs font-bold text-slate-700 dark:text-slate-200"
                    >
                        Cobrador *
                        <select
                            v-model="shootoutShooterId"
                            name="shooter_id"
                            required
                            class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm font-semibold dark:border-slate-600 dark:bg-slate-900"
                            data-test="shootout-cobrador"
                        >
                            <option value="" disabled>
                                Escolha o jogador…
                            </option>
                            <option
                                v-for="player in shootoutRoster"
                                :key="player.id"
                                :value="player.id"
                            >
                                #{{ player.jersey_number }} {{ player.name }}
                            </option>
                        </select>
                    </label>

                    <div
                        class="block text-xs font-bold text-slate-700 dark:text-slate-200"
                    >
                        Convertido?
                        <div class="mt-1 grid grid-cols-2 gap-2">
                            <button
                                type="button"
                                class="min-h-11 rounded-xl border text-xs font-black transition"
                                :class="
                                    shootoutScored
                                        ? 'border-emerald-600 bg-emerald-600 text-white'
                                        : 'border-slate-300 bg-white text-slate-700 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200'
                                "
                                data-test="shootout-convertido-sim"
                                @click="shootoutScored = true"
                            >
                                Sim (Gol)
                            </button>
                            <button
                                type="button"
                                class="min-h-11 rounded-xl border text-xs font-black transition"
                                :class="
                                    !shootoutScored
                                        ? 'border-red-600 bg-red-600 text-white'
                                        : 'border-slate-300 bg-white text-slate-700 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200'
                                "
                                data-test="shootout-convertido-nao"
                                @click="shootoutScored = false"
                            >
                                Não (Perdeu)
                            </button>
                        </div>
                    </div>
                </div>

                <button
                    type="submit"
                    class="flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-purple-700 px-4 text-sm font-bold text-white hover:bg-purple-600 disabled:opacity-50"
                    :disabled="submitting || shootoutShooterId === ''"
                    data-test="registrar-penalti"
                >
                    <Target class="size-4" />
                    Registrar cobrança de shootout
                </button>
            </form>

            <!-- List of Shootout Attempts -->
            <div v-if="shootoutEvents.length" class="mt-4 space-y-2">
                <h3
                    class="text-xs font-bold tracking-wider text-slate-500 uppercase dark:text-slate-400"
                >
                    Cobranças registradas ({{ shootoutEvents.length }})
                </h3>
                <div
                    v-for="ev in shootoutEvents"
                    :key="ev.id"
                    class="flex items-center justify-between gap-2 rounded-xl bg-slate-50 px-3 py-2 text-xs dark:bg-slate-800/50"
                >
                    <div class="flex min-w-0 items-center gap-2">
                        <span
                            class="rounded bg-slate-900 px-1.5 py-0.5 font-mono text-[10px] font-black text-white dark:bg-slate-700"
                        >
                            R{{ ev.shootout_round ?? 1 }}
                        </span>
                        <span
                            class="truncate font-bold text-slate-900 dark:text-slate-100"
                        >
                            {{ ev.primary_player_name ?? 'Jogador' }}
                        </span>
                        <span class="text-slate-500 dark:text-slate-400">
                            ({{ teamNameForEvent(ev) }})
                        </span>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <span
                            class="rounded-full px-2 py-0.5 text-[10px] font-bold"
                            :class="
                                ev.shootout_scored
                                    ? 'bg-emerald-100 text-emerald-800'
                                    : 'bg-red-100 text-red-800'
                            "
                        >
                            {{ ev.shootout_scored ? 'Convertido' : 'Perdido' }}
                        </span>
                        <button
                            v-if="game.status === 'live'"
                            type="button"
                            class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600"
                            :aria-label="`Remover cobrança #${ev.id}`"
                            @click="openRemoveEventModal(ev)"
                        >
                            <Trash2 class="size-3.5" />
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Events / Súmula List with Remove -->
        <section
            class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 dark:border-slate-700 dark:bg-slate-900"
            :class="{
                'lg:col-span-2': !(
                    canUseShootout &&
                    (game.status === 'live' ||
                        game.went_to_shootout ||
                        shootoutEvents.length > 0)
                ),
            }"
            data-test="lista-eventos"
        >
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h2
                        class="text-base font-black text-slate-900 dark:text-slate-100"
                    >
                        Eventos da súmula ({{ activeEventsDesc.length }})
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Gols (com até {{ maxAssists }} assistência(s)) e
                        cobranças registrados no servidor.
                    </p>
                </div>
            </div>

            <div
                v-if="!activeEventsDesc.length"
                class="mt-4 rounded-xl border border-dashed border-slate-200 bg-slate-50/60 p-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-800/40 dark:text-slate-400"
            >
                Nenhum gol ou evento registrado nesta partida ainda.
            </div>

            <div v-else class="mt-4 space-y-2.5">
                <div
                    v-for="event in activeEventsDesc"
                    :key="event.id"
                    class="flex items-center justify-between gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50"
                    :data-event-id="event.id"
                >
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl text-xs font-black text-white"
                            :style="{
                                backgroundColor: teamColorForEvent(event),
                            }"
                        >
                            <template v-if="event.type === 'shootout_attempt'">
                                SO
                            </template>
                            <template v-else-if="event.is_overtime">
                                PR
                            </template>
                            <template v-else>
                                {{ event.period ?? 1 }}º
                            </template>
                        </span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span
                                    class="text-sm font-black text-slate-900 dark:text-slate-100"
                                >
                                    {{ event.primary_player_name ?? 'Atleta' }}
                                </span>
                                <span
                                    class="rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-bold text-slate-700 dark:bg-slate-700 dark:text-slate-200"
                                >
                                    {{ teamNameForEvent(event) }}
                                </span>
                                <span
                                    v-if="event.is_overtime"
                                    class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-800"
                                >
                                    Prorrogação
                                </span>
                                <span
                                    v-if="event.type === 'shootout_attempt'"
                                    class="rounded px-1.5 py-0.5 text-[10px] font-bold"
                                    :class="
                                        event.shootout_scored
                                            ? 'bg-emerald-100 text-emerald-800'
                                            : 'bg-red-100 text-red-800'
                                    "
                                >
                                    Shootout R{{ event.shootout_round ?? 1 }}:
                                    {{
                                        event.shootout_scored
                                            ? 'Convertido'
                                            : 'Perdido'
                                    }}
                                </span>
                            </div>

                            <p
                                v-if="event.type === 'goal'"
                                class="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400"
                            >
                                <template
                                    v-if="
                                        event.first_assist_player_name &&
                                        event.second_assist_player_name
                                    "
                                >
                                    Assistências:
                                    {{ event.first_assist_player_name }} e
                                    {{ event.second_assist_player_name }}
                                </template>
                                <template
                                    v-else-if="event.first_assist_player_name"
                                >
                                    Assistência:
                                    {{ event.first_assist_player_name }}
                                </template>
                                <template v-else> Sem assistência </template>
                            </p>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <span
                            v-if="event.type === 'goal'"
                            class="text-xs font-black text-slate-600 tabular-nums dark:text-slate-300"
                        >
                            {{ formatClock(event.remaining_seconds) }}
                        </span>

                        <button
                            v-if="game.status === 'live'"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-bold text-red-600 hover:bg-red-50 dark:border-slate-700 dark:bg-slate-900"
                            :data-test="`remover-evento-${event.id}`"
                            @click="openRemoveEventModal(event)"
                        >
                            <Trash2 class="size-3.5" />
                            Remover
                        </button>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- Modal 1: Confirm Start Game -->
    <Teleport to="body">
        <div
            v-if="showStartConfirm"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/75 p-4"
            @click.self="showStartConfirm = false"
        >
            <div
                class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900"
            >
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-black">Iniciar partida</h2>
                    <button
                        type="button"
                        class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
                        @click="showStartConfirm = false"
                    >
                        <X class="size-5" />
                    </button>
                </div>

                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Confirmar o início da partida entre
                    <strong>{{ homeTeam?.name ?? game.home_team_name }}</strong>
                    e
                    <strong>{{ awayTeam?.name ?? game.away_team_name }}</strong
                    >? A súmula ficará ao vivo para lançamento de gols.
                </p>

                <div class="mt-6 flex gap-3">
                    <button
                        type="button"
                        class="min-h-12 flex-1 rounded-xl border border-slate-300 font-bold text-slate-700 dark:border-slate-600 dark:text-slate-200"
                        @click="showStartConfirm = false"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        class="min-h-12 flex-1 rounded-xl bg-emerald-600 font-bold text-white disabled:opacity-50"
                        data-test="confirmar-iniciar-jogo"
                        :disabled="submitting"
                        @click="confirmStartGame"
                    >
                        {{ submitting ? 'Iniciando…' : 'Confirmar início' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Modal 2: Goal Dialog (Scorer -> Assists) -->
    <Teleport to="body">
        <div
            v-if="goalDialog"
            class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/85 sm:items-center sm:p-6"
            @click.self="goalDialog = null"
        >
            <div
                class="max-h-[92svh] w-full max-w-3xl overflow-y-auto rounded-t-3xl bg-white p-5 sm:rounded-3xl dark:bg-slate-900"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p
                            class="text-xs font-bold tracking-wider text-slate-500 uppercase dark:text-slate-400"
                        >
                            Gol de {{ activeDialogTeam?.name ?? 'Equipe' }}
                        </p>
                        <h2 class="text-2xl font-black">
                            <template v-if="!goalDialog.scorer">
                                Quem fez o gol?
                            </template>
                            <template v-else-if="!goalDialog.firstAssist">
                                Quem deu a 1ª assistência?
                            </template>
                            <template v-else>
                                Quem deu a 2ª assistência?
                            </template>
                        </h2>
                        <p
                            v-if="goalDialog.scorer"
                            class="mt-1 text-sm font-semibold text-slate-600 dark:text-slate-300"
                        >
                            Autor: #{{ goalDialog.scorer.jersey_number }}
                            {{ goalDialog.scorer.name }}
                            <template v-if="goalDialog.firstAssist">
                                · 1ª Assist.: #{{
                                    goalDialog.firstAssist.jersey_number
                                }}
                                {{ goalDialog.firstAssist.name }}
                            </template>
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
                        aria-label="Cancelar lançamento"
                        @click="goalDialog = null"
                    >
                        <X class="size-5" />
                    </button>
                </div>

                <!-- Period & Overtime controls inside goal modal -->
                <div
                    class="mt-3 flex flex-wrap items-center gap-4 rounded-xl bg-slate-50 px-3 py-2 text-xs dark:bg-slate-800/50"
                >
                    <label class="inline-flex items-center gap-2 font-bold">
                        Período:
                        <input
                            v-model.number="goalDialog.period"
                            type="number"
                            min="1"
                            max="10"
                            class="w-16 rounded-lg border border-slate-300 bg-white px-2 py-1 text-center font-bold dark:border-slate-600 dark:bg-slate-900"
                        />
                    </label>

                    <label
                        v-if="canUseOvertime"
                        class="inline-flex cursor-pointer items-center gap-2 font-bold"
                    >
                        <input
                            v-model="goalDialog.isOvertime"
                            type="checkbox"
                            class="size-4 accent-amber-600"
                        />
                        <span>Gol na prorrogação</span>
                    </label>
                </div>

                <!-- Step 1: Pick Scorer -->
                <div
                    v-if="!goalDialog.scorer"
                    class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3"
                >
                    <button
                        v-for="player in activeDialogRoster"
                        :key="player.id"
                        type="button"
                        class="flex min-h-20 cursor-pointer items-center gap-3 rounded-2xl border-2 border-slate-200 p-3 text-left font-bold transition hover:border-slate-900 dark:border-slate-700 dark:hover:border-slate-300"
                        :data-scorer-id="player.id"
                        @click="selectGoalScorer(player)"
                    >
                        <span
                            class="flex size-12 shrink-0 items-center justify-center rounded-xl text-base font-black text-white"
                            :style="{
                                backgroundColor:
                                    activeDialogTeam?.primary_color ||
                                    '#0f172a',
                            }"
                        >
                            #{{ player.jersey_number }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <strong
                                class="block truncate text-sm text-slate-900 dark:text-slate-100"
                            >
                                {{ player.name }}
                            </strong>
                            <span
                                v-if="player.is_goalkeeper"
                                class="text-[10px] font-bold text-emerald-700"
                            >
                                Goleiro
                            </span>
                        </span>
                    </button>
                </div>

                <!-- Step 2: Pick 1st Assist (or Sem assistência) -->
                <div
                    v-else-if="!goalDialog.firstAssist"
                    class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3"
                >
                    <button
                        type="button"
                        class="col-span-full flex min-h-14 items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-slate-300 font-bold text-slate-800 hover:border-slate-900 dark:border-slate-600 dark:text-slate-100 dark:hover:border-slate-300"
                        data-test="sem-assistencia"
                        :disabled="submitting"
                        @click="selectFirstAssist(null)"
                    >
                        Sem assistência (confirmar gol)
                    </button>

                    <button
                        v-for="player in availableFirstAssistPlayers"
                        :key="player.id"
                        type="button"
                        class="flex min-h-20 items-center gap-3 rounded-2xl border-2 border-slate-200 p-3 text-left font-bold transition hover:border-slate-900 dark:border-slate-700 dark:hover:border-slate-300"
                        :data-assist-id="player.id"
                        :disabled="submitting"
                        @click="selectFirstAssist(player)"
                    >
                        <span
                            class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-sm font-black text-white dark:bg-slate-700"
                        >
                            #{{ player.jersey_number }}
                        </span>
                        <span
                            class="truncate text-sm text-slate-900 dark:text-slate-100"
                        >
                            {{ player.name }}
                        </span>
                    </button>
                </div>

                <!-- Step 3: Pick 2nd Assist (when maxAssists >= 2) -->
                <div v-else class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <button
                        type="button"
                        class="col-span-full flex min-h-14 items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-slate-300 font-bold text-slate-800 hover:border-slate-900 dark:border-slate-600 dark:text-slate-100 dark:hover:border-slate-300"
                        data-test="confirmar-uma-assistencia"
                        :disabled="submitting"
                        @click="selectSecondAssist(null)"
                    >
                        Sem 2ª assistência (confirmar com 1 assistência)
                    </button>

                    <button
                        v-for="player in availableSecondAssistPlayers"
                        :key="player.id"
                        type="button"
                        class="flex min-h-20 items-center gap-3 rounded-2xl border-2 border-slate-200 p-3 text-left font-bold transition hover:border-slate-900 dark:border-slate-700 dark:hover:border-slate-300"
                        :data-second-assist-id="player.id"
                        :disabled="submitting"
                        @click="selectSecondAssist(player)"
                    >
                        <span
                            class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-sm font-black text-white dark:bg-slate-700"
                        >
                            #{{ player.jersey_number }}
                        </span>
                        <span
                            class="truncate text-sm text-slate-900 dark:text-slate-100"
                        >
                            {{ player.name }}
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Modal 3: Confirm Remove Event (motivo) -->
    <Teleport to="body">
        <div
            v-if="eventToRemove"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/75 p-4"
            @click.self="closeRemoveEventModal"
        >
            <form
                class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900"
                @submit.prevent="confirmRemoveEvent"
            >
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-black">Remover evento da súmula</h2>
                    <button
                        type="button"
                        class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
                        @click="closeRemoveEventModal"
                    >
                        <X class="size-5" />
                    </button>
                </div>

                <p class="mt-2 text-xs text-slate-600 dark:text-slate-300">
                    O evento de
                    <strong>{{
                        eventToRemove.primary_player_name ?? 'Atleta'
                    }}</strong>
                    ({{ teamNameForEvent(eventToRemove) }}) será anulado e o
                    placar recalculado, mantendo registro na auditoria.
                </p>

                <label class="mt-4 block text-sm font-bold">
                    Motivo da remoção / retificação *
                    <input
                        v-model="removeEventReason"
                        name="motivo"
                        type="text"
                        required
                        maxlength="500"
                        class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 font-normal dark:border-slate-600"
                        placeholder="Ex.: Erro de digitação ou gol anulado pela arbitragem"
                        data-test="motivo-remover-evento"
                    />
                </label>

                <div class="mt-6 flex gap-3">
                    <button
                        type="button"
                        class="min-h-12 flex-1 rounded-xl border border-slate-300 font-bold text-slate-700 dark:border-slate-600 dark:text-slate-200"
                        @click="closeRemoveEventModal"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        class="min-h-12 flex-1 rounded-xl bg-red-600 font-bold text-white disabled:opacity-50"
                        data-test="confirmar-remover-evento"
                        :disabled="submitting"
                    >
                        {{ submitting ? 'Removendo…' : 'Confirmar remoção' }}
                    </button>
                </div>
            </form>
        </div>
    </Teleport>

    <!-- Modal 4: Confirm Finish Game -->
    <Teleport to="body">
        <div
            v-if="showFinishConfirm"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/75 p-4"
            @click.self="showFinishConfirm = false"
        >
            <div
                class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900"
            >
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-black">Finalizar partida</h2>
                    <button
                        type="button"
                        class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
                        @click="showFinishConfirm = false"
                    >
                        <X class="size-5" />
                    </button>
                </div>

                <div
                    class="mt-3 rounded-xl bg-slate-50 p-3 text-center dark:bg-slate-800/50"
                >
                    <p
                        class="text-xs font-bold text-slate-500 dark:text-slate-400"
                    >
                        Placar confirmado
                    </p>
                    <p
                        class="mt-1 text-lg font-black text-slate-900 dark:text-slate-100"
                    >
                        {{ homeTeam?.name ?? game.home_team_name }}
                        {{ game.home_regular_score }} ×
                        {{ game.away_regular_score }}
                        {{ awayTeam?.name ?? game.away_team_name }}
                    </p>
                    <p
                        v-if="game.went_to_shootout || shootoutEvents.length"
                        class="mt-1 text-xs font-bold text-purple-700"
                    >
                        Shootout: {{ game.home_shootout_score }} ×
                        {{ game.away_shootout_score }}
                    </p>
                </div>

                <div
                    v-if="
                        requiresWinnerToFinish &&
                        isRegularScoreTied &&
                        game.home_shootout_score === game.away_shootout_score
                    "
                    class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-semibold text-amber-900"
                >
                    Atenção: este confronto exige um vencedor. Registre o gol de
                    prorrogação ou o desempate nos pênaltis/shootout antes de
                    encerrar.
                </div>

                <div
                    v-if="pageErrors.championship"
                    class="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-xs font-bold text-red-700"
                >
                    {{ pageErrors.championship }}
                </div>

                <div class="mt-6 flex gap-3">
                    <button
                        type="button"
                        class="min-h-12 flex-1 rounded-xl border border-slate-300 font-bold text-slate-700 dark:border-slate-600 dark:text-slate-200"
                        @click="showFinishConfirm = false"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        class="min-h-12 flex-1 rounded-xl bg-emerald-600 font-bold text-white disabled:opacity-50"
                        data-test="confirmar-finalizar-jogo"
                        :disabled="submitting"
                        @click="confirmFinishGame"
                    >
                        {{
                            submitting
                                ? 'Encerrando…'
                                : 'Confirmar encerramento'
                        }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Modal 5: Reopen Finished Game (motivo required + resetar_chave) -->
    <Teleport to="body">
        <div
            v-if="showReopenModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/75 p-4"
            @click.self="showReopenModal = false"
        >
            <form
                class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900"
                @submit.prevent="submitReopenGame"
            >
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-black">
                        Reabrir partida para retificação
                    </h2>
                    <button
                        type="button"
                        class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
                        @click="showReopenModal = false"
                    >
                        <X class="size-5" />
                    </button>
                </div>

                <p class="mt-2 text-xs text-slate-600 dark:text-slate-300">
                    Reabrir a partida permite adicionar ou remover
                    gols/cobranças da súmula. O estado anterior e o motivo ficam
                    gravados na auditoria do campeonato.
                </p>

                <div
                    v-if="pageErrors.championship || localError"
                    class="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-xs font-bold text-red-700"
                >
                    {{ pageErrors.championship || localError }}
                </div>

                <label class="mt-4 block text-sm font-bold">
                    Motivo da reabertura *
                    <textarea
                        v-model="reopenReason"
                        name="motivo"
                        required
                        rows="3"
                        maxlength="500"
                        class="mt-1 w-full rounded-xl border border-slate-300 p-3 font-normal dark:border-slate-600"
                        placeholder="Ex.: Correção de súmula oficial solicitada pela mesa"
                        data-test="motivo-reabrir"
                    />
                </label>

                <label
                    v-if="game.phase === 'group'"
                    class="mt-3 flex cursor-pointer items-start gap-2.5 rounded-xl border p-3 text-xs"
                    :class="
                        bracketNeedsResetHint
                            ? 'border-amber-300 bg-amber-50 text-amber-950'
                            : 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-200'
                    "
                >
                    <input
                        v-model="reopenResetBracket"
                        name="resetar_chave"
                        type="checkbox"
                        class="mt-0.5 size-4 accent-amber-700"
                        data-test="checkbox-resetar-chave"
                    />
                    <div>
                        <span class="font-bold">
                            Reiniciar chave do mata-mata (caso já tenha sido
                            gerada e nenhum jogo tenha iniciado)
                        </span>
                        <span class="mt-0.5 block text-[11px] opacity-80">
                            Se o mata-mata já tiver começado, a reabertura de
                            jogos da fase de grupos permanecerá bloqueada.
                        </span>
                    </div>
                </label>

                <div class="mt-6 flex gap-3">
                    <button
                        type="button"
                        class="min-h-12 flex-1 rounded-xl border border-slate-300 font-bold text-slate-700 dark:border-slate-600 dark:text-slate-200"
                        @click="showReopenModal = false"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        class="min-h-12 flex-1 rounded-xl bg-amber-700 font-bold text-white disabled:opacity-50"
                        data-test="confirmar-reabrir-jogo"
                        :disabled="submitting"
                    >
                        {{ submitting ? 'Reabrindo…' : 'Confirmar reabertura' }}
                    </button>
                </div>
            </form>
        </div>
    </Teleport>
</template>
