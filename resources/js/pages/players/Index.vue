<script setup lang="ts">
import PlayerPhoto from '@/components/hockey/PlayerPhoto.vue';
import StarRating from '@/components/hockey/StarRating.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useToast } from '@/composables/useToast';
import {
    changePresence,
    changePresenceBatch,
    loadPlayers,
} from '@/lib/offlineStorage';
import { syncNow } from '@/lib/offlineSync';
import type { Player } from '@/types/hockey';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    CheckCheck,
    Pencil,
    Plus,
    Search,
    Shuffle,
    Trash2,
    UserCheck,
    Users,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{ players: Player[]; presentCount: number }>();
const page = usePage();
const { confirm } = useConfirm();
const toast = useToast();
const accountId = (page.props.team as { id: number }).id;
const players = ref<Player[]>(
    loadPlayers(localStorage, accountId, props.players),
);
const online = ref(navigator.onLine);
const editing = ref<Player | null>(null);
const showForm = ref(false);
const search = ref('');
const statusFilter = ref<'all' | 'present' | 'absent'>('all');
const sortBy = ref<'name' | 'number' | 'level' | 'present'>('name');
const photoPreview = ref<string | null>(null);
let objectUrl: string | null = null;
const offlineMessage = ref('');

const form = useForm<{
    name: string;
    jersey_number: number | '';
    level: number;
    present: boolean;
    photo: File | null;
    remove_photo: boolean;
}>({
    name: '',
    jersey_number: '',
    level: 3,
    present: true,
    photo: null,
    remove_photo: false,
});

const presentCount = computed(
    () => players.value.filter((player) => player.present).length,
);

const absentCount = computed(() => players.value.length - presentCount.value);

const presencePercent = computed(() =>
    players.value.length
        ? Math.round((presentCount.value / players.value.length) * 100)
        : 0,
);

const filteredPlayers = computed(() => {
    const query = search.value.trim().toLowerCase();
    const list = players.value.filter((player) => {
        if (statusFilter.value === 'present' && !player.present) return false;
        if (statusFilter.value === 'absent' && player.present) return false;
        if (!query) return true;
        return `${player.name} ${player.jersey_number}`
            .toLowerCase()
            .includes(query);
    });

    return [...list].sort((a, b) => {
        if (sortBy.value === 'number') {
            return a.jersey_number - b.jersey_number;
        }
        if (sortBy.value === 'level') {
            const diff = Number(b.level) - Number(a.level);
            return diff !== 0 ? diff : a.name.localeCompare(b.name, 'pt-BR');
        }
        if (sortBy.value === 'present') {
            if (a.present !== b.present) return a.present ? -1 : 1;
            return a.name.localeCompare(b.name, 'pt-BR');
        }
        return a.name.localeCompare(b.name, 'pt-BR');
    });
});

function formatLevel(level: number | string): string {
    return Number(level).toLocaleString('pt-BR', {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
    });
}

function revokePreview() {
    if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
        objectUrl = null;
    }
}

const requiresConnection = (action: string): boolean => {
    if (online.value) return false;
    offlineMessage.value = `Sem conexão — conecte-se para ${action}.`;
    return true;
};

const updateOnline = () => {
    online.value = navigator.onLine;
};

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape' && showForm.value) {
        closeForm();
    }
}

onMounted(() => {
    window.addEventListener('online', updateOnline);
    window.addEventListener('offline', updateOnline);
    window.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    window.removeEventListener('online', updateOnline);
    window.removeEventListener('offline', updateOnline);
    window.removeEventListener('keydown', onKeydown);
    revokePreview();
});

watch(
    () => props.players,
    (serverPlayers) => {
        players.value = loadPlayers(localStorage, accountId, serverPlayers);
    },
);

function openNew() {
    if (requiresConnection('cadastrar jogadores')) return;
    revokePreview();
    editing.value = null;
    showForm.value = true;
    photoPreview.value = null;
    form.reset();
    form.jersey_number = '';
    form.level = 3;
    form.present = true;
    form.remove_photo = false;
}

function openEdit(player: Player) {
    if (requiresConnection('editar jogadores')) return;
    revokePreview();
    editing.value = player;
    showForm.value = true;
    photoPreview.value = player.photo_url;
    form.name = player.name;
    form.jersey_number = player.jersey_number;
    form.level = Number(player.level);
    form.present = player.present;
    form.photo = null;
    form.remove_photo = false;
}

function closeForm() {
    revokePreview();
    showForm.value = false;
    form.clearErrors();
}

function preview(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] || null;
    revokePreview();
    form.photo = file;
    form.remove_photo = false;
    if (file) {
        objectUrl = URL.createObjectURL(file);
        photoPreview.value = objectUrl;
    } else {
        photoPreview.value = editing.value?.photo_url ?? null;
    }
}

function clearPhoto() {
    revokePreview();
    form.photo = null;
    form.remove_photo = true;
    photoPreview.value = null;
}

function save() {
    if (requiresConnection('salvar jogadores')) return;
    if (editing.value) {
        form.transform((data) => ({ ...data, _method: 'patch' })).post(
            `/jogadores/${editing.value.id}`,
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => closeForm(),
            },
        );
    } else {
        form.post('/jogadores', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => closeForm(),
        });
    }
}

function presenceAll(present: boolean) {
    players.value = changePresenceBatch(
        localStorage,
        accountId,
        players.value,
        present,
    );
    void syncNow();
    toast.info(
        present
            ? 'Todos os jogadores marcados como presentes.'
            : 'Lista de presença limpa.',
        2200,
    );
}

function toggle(player: Player) {
    players.value = changePresence(
        localStorage,
        accountId,
        players.value,
        player.id,
        !player.present,
    );
    void syncNow();
}

async function remove(player: Player) {
    if (requiresConnection('remover jogadores')) return;
    const accepted = await confirm({
        title: `Remover ${player.name}?`,
        message:
            'O jogador sai do elenco atual, mas todos os gols e assistências no histórico serão preservados.',
        confirmLabel: 'Remover jogador',
        variant: 'danger',
    });
    if (accepted) {
        router.delete(`/jogadores/${player.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Jogadores e presença" />

    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p
                class="text-[11px] font-extrabold tracking-widest text-[var(--team-primary)] uppercase dark:text-emerald-400"
            >
                Elenco do time
            </p>
            <h1
                class="mt-0.5 text-2xl font-extrabold tracking-tight text-slate-950 sm:text-3xl dark:text-white"
            >
                Jogadores e presença
            </h1>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <Link
                v-if="presentCount >= 2"
                href="/sorteio"
                class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 text-sm font-bold text-slate-800 shadow-xs transition-all hover:border-slate-300 hover:bg-slate-50 active:scale-[0.98] dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800"
            >
                <Shuffle class="size-4 text-[var(--team-primary)]" />
                <span>Sortear ({{ presentCount }})</span>
                <ArrowRight class="size-4 text-slate-400" />
            </Link>
            <button
                type="button"
                class="flex min-h-11 items-center gap-2 rounded-xl bg-[var(--team-primary)] px-4 text-sm font-bold text-[var(--team-primary-text)] shadow-sm transition-all hover:brightness-110 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50"
                data-test="novo-jogador"
                :disabled="!online"
                @click="openNew"
            >
                <Plus class="size-4.5" />
                Novo jogador
            </button>
        </div>
    </div>

    <div
        v-if="offlineMessage"
        class="mb-4 flex items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 dark:border-amber-800/60 dark:bg-amber-950/50 dark:text-amber-100"
        role="status"
    >
        <span>{{ offlineMessage }}</span>
        <button
            type="button"
            class="shrink-0 font-bold underline"
            @click="offlineMessage = ''"
        >
            Fechar
        </button>
    </div>

    <section class="surface-card mb-5 rounded-2xl p-4 sm:p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <span
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-[var(--team-primary-soft)] text-[var(--team-primary)] dark:text-emerald-400"
                >
                    <UserCheck class="size-5" />
                </span>
                <div>
                    <h2
                        class="text-sm font-extrabold text-slate-950 dark:text-white"
                    >
                        Quem veio hoje?
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        <strong
                            class="font-extrabold text-emerald-700 dark:text-emerald-400"
                            >{{ presentCount }}</strong
                        >
                        de {{ players.length }} confirmados ({{
                            presencePercent
                        }}%)
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="inline-flex min-h-9 items-center gap-1.5 rounded-xl bg-emerald-100 px-3 text-xs font-bold text-emerald-900 transition-colors hover:bg-emerald-200/80 dark:bg-emerald-950/70 dark:text-emerald-200 dark:hover:bg-emerald-900/70"
                    @click="presenceAll(true)"
                >
                    <CheckCheck class="size-3.5" />
                    Marcar todos
                </button>
                <button
                    type="button"
                    class="min-h-9 rounded-xl bg-slate-100 px-3 text-xs font-bold text-slate-700 transition-colors hover:bg-slate-200/80 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"
                    @click="presenceAll(false)"
                >
                    Limpar
                </button>
            </div>
        </div>

        <div
            class="mt-3.5 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"
        >
            <div
                class="h-full rounded-full transition-all duration-300"
                :style="{
                    width: `${presencePercent}%`,
                    background:
                        'linear-gradient(90deg, var(--team-primary), var(--team-secondary))',
                }"
            />
        </div>

        <div class="mt-4 flex flex-col gap-2.5 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3.5 size-4.5 -translate-y-1/2 text-slate-400"
                />
                <input
                    v-model="search"
                    type="search"
                    class="min-h-11 w-full rounded-xl border border-slate-200 bg-slate-50/70 pr-3 pl-10 text-sm font-medium text-slate-950 transition-colors focus:border-[var(--team-primary)] focus:bg-white focus:outline-none dark:border-slate-700 dark:bg-slate-950/60 dark:text-slate-100 dark:focus:bg-slate-950"
                    placeholder="Buscar por nome ou número da camisa"
                    aria-label="Buscar jogador"
                />
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <div
                    class="inline-flex rounded-xl bg-slate-100 p-1 text-xs font-bold dark:bg-slate-800"
                    role="group"
                    aria-label="Filtrar presença"
                >
                    <button
                        type="button"
                        class="min-h-8 rounded-lg px-2.5 transition-colors"
                        :class="
                            statusFilter === 'all'
                                ? 'bg-white text-slate-950 shadow-xs dark:bg-slate-700 dark:text-white'
                                : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'
                        "
                        @click="statusFilter = 'all'"
                    >
                        Todos ({{ players.length }})
                    </button>
                    <button
                        type="button"
                        class="min-h-8 rounded-lg px-2.5 transition-colors"
                        :class="
                            statusFilter === 'present'
                                ? 'bg-white text-emerald-700 shadow-xs dark:bg-slate-700 dark:text-emerald-300'
                                : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'
                        "
                        @click="statusFilter = 'present'"
                    >
                        Presentes ({{ presentCount }})
                    </button>
                    <button
                        type="button"
                        class="min-h-8 rounded-lg px-2.5 transition-colors"
                        :class="
                            statusFilter === 'absent'
                                ? 'bg-white text-slate-950 shadow-xs dark:bg-slate-700 dark:text-white'
                                : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'
                        "
                        @click="statusFilter = 'absent'"
                    >
                        Ausentes ({{ absentCount }})
                    </button>
                </div>

                <select
                    v-model="sortBy"
                    aria-label="Ordenar jogadores"
                    class="min-h-10 rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 focus:border-[var(--team-primary)] focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
                >
                    <option value="name">Ordem: Nome</option>
                    <option value="number">Ordem: Número</option>
                    <option value="level">Ordem: Maior nível</option>
                    <option value="present">Ordem: Presentes</option>
                </select>
            </div>
        </div>
    </section>

    <div
        v-if="filteredPlayers.length"
        class="grid gap-3 sm:grid-cols-2 2xl:grid-cols-3"
    >
        <article
            v-for="player in filteredPlayers"
            :key="player.id"
            class="group relative overflow-hidden rounded-2xl border transition-all duration-200"
            :class="
                player.present
                    ? 'border-emerald-300/80 bg-white shadow-xs hover:shadow-md dark:border-emerald-800/70 dark:bg-slate-900'
                    : 'border-slate-200/80 bg-white/65 opacity-80 hover:opacity-100 dark:border-slate-800 dark:bg-slate-900/50'
            "
            :data-player-id="player.id"
        >
            <div
                class="absolute inset-y-0 left-0 w-1 transition-colors"
                :class="
                    player.present
                        ? 'bg-emerald-500'
                        : 'bg-slate-200 dark:bg-slate-700'
                "
            />
            <div class="flex items-center gap-1.5 py-2.5 pr-2 pl-3.5">
                <label
                    class="flex min-w-0 flex-1 cursor-pointer items-center gap-2.5"
                >
                    <input
                        type="checkbox"
                        class="size-5 shrink-0 rounded-md accent-emerald-600"
                        :checked="player.present"
                        :aria-label="`Presença de ${player.name}`"
                        @change="toggle(player)"
                    />
                    <PlayerPhoto :player="player" size="size-12" />
                    <div class="min-w-0 flex-1">
                        <h2
                            class="truncate text-sm font-extrabold text-slate-950 dark:text-white"
                        >
                            {{ player.name }}
                        </h2>
                        <div class="mt-1 flex flex-wrap items-center gap-1.5">
                            <span
                                class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[11px] font-bold"
                                :class="
                                    player.present
                                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300'
                                        : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'
                                "
                            >
                                {{ player.present ? 'Presente' : 'Ausente' }}
                            </span>
                            <span
                                class="inline-flex items-center rounded-md bg-amber-100/80 px-1.5 py-0.5 text-[11px] font-extrabold text-amber-900 tabular-nums dark:bg-amber-950/60 dark:text-amber-300"
                                :title="`Nível técnico: ${formatLevel(player.level)}`"
                            >
                                {{ formatLevel(player.level) }} ★
                            </span>
                        </div>
                    </div>
                    <strong
                        class="shrink-0 font-display text-3xl leading-none font-extrabold tracking-tight text-slate-800 tabular-nums dark:text-slate-100"
                        >#{{ player.jersey_number }}</strong
                    >
                </label>
                <div class="flex shrink-0 flex-col gap-0.5 sm:flex-row">
                    <button
                        type="button"
                        class="flex size-8 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-800 disabled:cursor-not-allowed disabled:opacity-40 dark:hover:bg-slate-800 dark:hover:text-white"
                        :aria-label="`Editar ${player.name}`"
                        :disabled="!online"
                        @click="openEdit(player)"
                    >
                        <Pencil class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="flex size-8 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-red-50 hover:text-red-600 disabled:cursor-not-allowed disabled:opacity-40 dark:hover:bg-red-950/60 dark:hover:text-red-400"
                        :aria-label="`Remover ${player.name}`"
                        :disabled="!online"
                        @click="remove(player)"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </div>
            </div>
        </article>
    </div>

    <div
        v-else-if="!players.length"
        class="surface-card rounded-3xl border-dashed p-10 text-center"
    >
        <span
            class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-[var(--team-primary-soft)] text-[var(--team-primary)]"
        >
            <Users class="size-7" />
        </span>
        <h2 class="mt-4 text-xl font-extrabold text-slate-950 dark:text-white">
            Monte o seu elenco
        </h2>
        <p
            class="mx-auto mt-1.5 max-w-sm text-sm text-slate-500 dark:text-slate-400"
        >
            Cadastre nome, número da camisa, foto e nível técnico para sortear
            equipes equilibradas.
        </p>
        <button
            type="button"
            class="mt-5 inline-flex min-h-12 items-center gap-2 rounded-xl bg-[var(--team-primary)] px-5 font-bold text-[var(--team-primary-text)] shadow-sm transition-all hover:brightness-110 active:scale-[0.98] disabled:opacity-50"
            :disabled="!online"
            @click="openNew"
        >
            <Plus class="size-5" />
            Cadastrar primeiro jogador
        </button>
    </div>

    <div
        v-else
        class="surface-card rounded-2xl p-8 text-center text-sm text-slate-500 dark:text-slate-400"
    >
        Nenhum jogador encontrado com esse filtro.
    </div>

    <Teleport to="body">
        <div
            v-if="showForm"
            class="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-slate-950/75 p-0 backdrop-blur-xs sm:items-center sm:p-4"
            role="dialog"
            aria-modal="true"
            @click.self="closeForm"
        >
            <form
                class="animate-toast-in surface-card w-full max-w-lg rounded-t-3xl p-5 sm:my-5 sm:rounded-3xl sm:p-6"
                @submit.prevent="save"
            >
                <div class="flex items-center justify-between">
                    <h2
                        class="text-xl font-extrabold text-slate-950 dark:text-white"
                    >
                        {{ editing ? 'Editar jogador' : 'Novo jogador' }}
                    </h2>
                    <button
                        type="button"
                        class="rounded-xl p-2 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-white"
                        aria-label="Fechar"
                        @click="closeForm"
                    >
                        <X class="size-5" />
                    </button>
                </div>

                <div class="mt-5 flex items-center gap-4">
                    <img
                        v-if="photoPreview"
                        :src="photoPreview"
                        alt="Prévia da foto"
                        class="size-20 rounded-2xl border border-slate-200 object-cover shadow-xs dark:border-slate-700"
                    />
                    <span
                        v-else
                        class="flex size-20 items-center justify-center rounded-2xl bg-slate-100 font-display text-3xl font-extrabold text-slate-400 dark:bg-slate-800"
                    >
                        #{{ form.jersey_number || '?' }}
                    </span>
                    <div class="space-y-1.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <label
                                class="inline-flex min-h-10 cursor-pointer items-center rounded-xl bg-slate-100 px-4 text-xs font-bold text-slate-800 transition-colors hover:bg-slate-200 has-disabled:cursor-not-allowed has-disabled:opacity-50 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
                            >
                                {{
                                    photoPreview
                                        ? 'Trocar foto'
                                        : 'Adicionar foto'
                                }}
                                <input
                                    type="file"
                                    class="sr-only"
                                    :disabled="!online"
                                    accept="image/jpeg,image/png,image/webp"
                                    @change="preview"
                                />
                            </label>
                            <button
                                v-if="photoPreview"
                                type="button"
                                class="min-h-10 rounded-xl px-3 text-xs font-bold text-red-600 transition-colors hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/60"
                                @click="clearPhoto"
                            >
                                Remover
                            </button>
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            JPG, PNG ou WebP, até 4 MB
                        </p>
                    </div>
                </div>
                <p
                    v-if="form.errors.photo"
                    class="mt-2 text-xs font-semibold text-red-600 dark:text-red-400"
                >
                    {{ form.errors.photo }}
                </p>

                <div class="mt-5 grid gap-4 sm:grid-cols-[1fr_120px]">
                    <label
                        class="text-sm font-bold text-slate-800 dark:text-slate-200"
                    >
                        Nome
                        <input
                            v-model="form.name"
                            name="name"
                            required
                            maxlength="100"
                            class="mt-1.5 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3.5 font-normal text-slate-950 focus:border-[var(--team-primary)] focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            placeholder="Nome do jogador"
                        />
                        <span
                            v-if="form.errors.name"
                            class="mt-1 block text-xs text-red-600 dark:text-red-400"
                            >{{ form.errors.name }}</span
                        >
                    </label>
                    <label
                        class="text-sm font-bold text-slate-800 dark:text-slate-200"
                    >
                        Número
                        <input
                            v-model.number="form.jersey_number"
                            name="jersey_number"
                            required
                            type="number"
                            min="0"
                            max="999"
                            class="mt-1.5 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3.5 font-normal text-slate-950 focus:border-[var(--team-primary)] focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        />
                        <span
                            v-if="form.errors.jersey_number"
                            class="mt-1 block text-xs text-red-600 dark:text-red-400"
                            >{{ form.errors.jersey_number }}</span
                        >
                    </label>
                </div>

                <fieldset class="mt-5">
                    <legend
                        class="mb-2 text-sm font-bold text-slate-800 dark:text-slate-200"
                    >
                        Nível técnico
                    </legend>
                    <StarRating v-model="form.level" />
                    <input type="hidden" name="level" :value="form.level" />
                    <p
                        class="mt-1.5 text-xs text-slate-500 dark:text-slate-400"
                    >
                        Toque na metade esquerda ou direita da estrela para
                        meia-estrela.
                    </p>
                </fieldset>

                <label
                    class="mt-5 flex cursor-pointer items-center gap-3 rounded-2xl bg-slate-50 p-3.5 text-sm font-bold text-slate-800 dark:bg-slate-800/60 dark:text-slate-100"
                >
                    <input
                        v-model="form.present"
                        type="checkbox"
                        class="size-5 rounded accent-emerald-600"
                    />
                    Disponível para o sorteio de hoje
                </label>

                <div class="mt-6 flex gap-3">
                    <button
                        type="button"
                        class="min-h-12 flex-1 rounded-xl border border-slate-300 font-bold text-slate-700 transition-colors hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                        @click="closeForm"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        class="min-h-12 flex-1 rounded-xl bg-[var(--team-primary)] font-bold text-[var(--team-primary-text)] shadow-sm transition-all hover:brightness-110 active:scale-[0.98] disabled:opacity-50"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Salvando…' : 'Salvar jogador' }}
                    </button>
                </div>
            </form>
        </div>
    </Teleport>
</template>
