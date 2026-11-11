<script setup lang="ts">
import ChampionshipTeamBadge from '@/components/championships/ChampionshipTeamBadge.vue';
import { router, useForm } from '@inertiajs/vue3';
import {
    Lock,
    Pencil,
    Plus,
    Shield,
    Trash2,
    UserPlus,
    Users,
    X,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import type {
    ChampionshipProp,
    EnrolledPlayerProp,
    EnrolledTeamProp,
    PlayerPosition,
} from '@/types/championship';

const props = defineProps<{
    championship: ChampionshipProp;
    teams: EnrolledTeamProp[];
}>();

const isDraft = computed(() => props.championship.status === 'draft');
const isCompleted = computed(() => props.championship.status === 'completed');

const positionLabels: Record<PlayerPosition, string> = {
    goalkeeper: 'Goleiro',
    defender: 'Defensor',
    forward: 'Atacante',
    universal: 'Universal',
};

// Team modal state
const showTeamModal = ref(false);
const editingTeam = ref<EnrolledTeamProp | null>(null);
const logoPreview = ref<string | null>(null);

const teamForm = useForm<{
    name: string;
    short_name: string;
    primary_color: string;
    secondary_color: string;
    seed_pot: number | '';
    logo: File | null;
    remove_logo: boolean;
}>({
    name: '',
    short_name: '',
    primary_color: '#2563eb',
    secondary_color: '#ffffff',
    seed_pot: '',
    logo: null,
    remove_logo: false,
});

function openNewTeam() {
    editingTeam.value = null;
    logoPreview.value = null;
    teamForm.reset();
    teamForm.clearErrors();
    teamForm.primary_color = '#2563eb';
    teamForm.secondary_color = '#ffffff';
    teamForm.seed_pot = '';
    showTeamModal.value = true;
}

function openEditTeam(team: EnrolledTeamProp) {
    editingTeam.value = team;
    logoPreview.value = team.logo_url;
    teamForm.clearErrors();
    teamForm.name = team.name;
    teamForm.short_name = team.short_name ?? '';
    teamForm.primary_color = team.primary_color || '#2563eb';
    teamForm.secondary_color = team.secondary_color || '#ffffff';
    teamForm.seed_pot = team.seed_pot ?? '';
    teamForm.logo = null;
    teamForm.remove_logo = false;
    showTeamModal.value = true;
}

function closeTeamModal() {
    showTeamModal.value = false;
    teamForm.clearErrors();
}

function onLogoChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    teamForm.logo = file;
    teamForm.remove_logo = false;
    logoPreview.value = file
        ? URL.createObjectURL(file)
        : (editingTeam.value?.logo_url ?? null);
}

function clearLogo() {
    teamForm.logo = null;
    teamForm.remove_logo = true;
    logoPreview.value = null;
}

function submitTeam() {
    const normalizePayload = (data: ReturnType<typeof teamForm.data>) => ({
        ...data,
        short_name:
            data.short_name.trim() !== '' ? data.short_name.trim() : null,
        seed_pot: data.seed_pot === '' ? null : Number(data.seed_pot),
    });

    if (editingTeam.value) {
        teamForm
            .transform((data) => ({
                ...normalizePayload(data),
                _method: 'patch',
            }))
            .post(
                `/campeonatos/${props.championship.id}/equipes/${editingTeam.value.id}`,
                {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: () => closeTeamModal(),
                },
            );
    } else {
        teamForm
            .transform((data) => normalizePayload(data))
            .post(`/campeonatos/${props.championship.id}/equipes`, {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => closeTeamModal(),
            });
    }
}

function confirmRemoveTeam(team: EnrolledTeamProp) {
    if (
        window.confirm(
            `Remover a equipe "${team.name}" e todos os seus jogadores inscritos?`,
        )
    ) {
        router.delete(
            `/campeonatos/${props.championship.id}/equipes/${team.id}`,
            { preserveScroll: true },
        );
    }
}

// Player modal state
const showPlayerModal = ref(false);
const activeTeamForPlayer = ref<EnrolledTeamProp | null>(null);
const editingPlayer = ref<EnrolledPlayerProp | null>(null);

const playerForm = useForm<{
    name: string;
    jersey_number: number | '';
    position: PlayerPosition;
    is_goalkeeper: boolean;
}>({
    name: '',
    jersey_number: '',
    position: 'universal',
    is_goalkeeper: false,
});

function openNewPlayer(team: EnrolledTeamProp) {
    activeTeamForPlayer.value = team;
    editingPlayer.value = null;
    playerForm.reset();
    playerForm.clearErrors();
    playerForm.position = 'universal';
    playerForm.is_goalkeeper = false;
    playerForm.jersey_number = '';
    showPlayerModal.value = true;
}

function openEditPlayer(team: EnrolledTeamProp, player: EnrolledPlayerProp) {
    activeTeamForPlayer.value = team;
    editingPlayer.value = player;
    playerForm.clearErrors();
    playerForm.name = player.name;
    playerForm.jersey_number = player.jersey_number;
    playerForm.position = player.position;
    playerForm.is_goalkeeper = player.is_goalkeeper;
    showPlayerModal.value = true;
}

function closePlayerModal() {
    showPlayerModal.value = false;
    playerForm.clearErrors();
}

function onPositionChange() {
    if (playerForm.position === 'goalkeeper') {
        playerForm.is_goalkeeper = true;
    }
}

function submitPlayer() {
    const team = activeTeamForPlayer.value;
    if (!team) return;

    if (editingPlayer.value) {
        playerForm.patch(
            `/campeonatos/${props.championship.id}/equipes/${team.id}/jogadores/${editingPlayer.value.id}`,
            {
                preserveScroll: true,
                onSuccess: () => closePlayerModal(),
            },
        );
    } else {
        playerForm.post(
            `/campeonatos/${props.championship.id}/equipes/${team.id}/jogadores`,
            {
                preserveScroll: true,
                onSuccess: () => closePlayerModal(),
            },
        );
    }
}

function confirmRemovePlayer(
    team: EnrolledTeamProp,
    player: EnrolledPlayerProp,
) {
    if (
        window.confirm(
            `Remover o jogador #${player.jersey_number} ${player.name} da equipe ${team.name}?`,
        )
    ) {
        router.delete(
            `/campeonatos/${props.championship.id}/equipes/${team.id}/jogadores/${player.id}`,
            { preserveScroll: true },
        );
    }
}
</script>

<template>
    <div class="space-y-4">
        <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4"
        >
            <div>
                <h2 class="text-lg font-black">Equipes inscritas e elencos</h2>
                <p class="text-xs text-slate-500">
                    {{ teams.length }} equipe(s) inscrita(s) · Mínimo sugerido
                    pelas regras:
                    {{
                        Math.max(
                            championship.rules.groups_count * 2,
                            championship.rules.groups_count *
                                championship.rules.qualifiers_per_group +
                                championship.rules.extra_best_qualifiers,
                        )
                    }}
                    equipes.
                </p>
            </div>

            <button
                v-if="isDraft"
                type="button"
                class="flex min-h-11 items-center gap-2 rounded-xl bg-[var(--team-primary)] px-4 text-sm font-bold text-white"
                data-test="nova-equipe"
                @click="openNewTeam"
            >
                <Plus class="size-4" />Inscrever equipe
            </button>
            <div
                v-else
                class="inline-flex items-center gap-1.5 rounded-xl bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-600"
            >
                <Lock class="size-3.5" />
                Inscrição/remoção de equipes encerrada após o sorteio
            </div>
        </div>

        <div v-if="teams.length" class="grid gap-4 lg:grid-cols-2">
            <section
                v-for="team in teams"
                :key="team.id"
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white"
                :data-team-id="team.id"
            >
                <div
                    class="h-1.5"
                    :style="{
                        backgroundColor: team.primary_color || '#2563eb',
                    }"
                />

                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4"
                >
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <ChampionshipTeamBadge
                            :name="team.name"
                            :short-name="team.short_name"
                            :logo-url="team.logo_url"
                            :primary-color="team.primary_color"
                            :secondary-color="team.secondary_color"
                            size="md"
                        />
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="truncate text-base font-black">
                                    {{ team.name }}
                                </h3>
                                <span
                                    v-if="team.short_name"
                                    class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-bold text-slate-600"
                                >
                                    {{ team.short_name }}
                                </span>
                                <span
                                    v-if="team.group_code"
                                    class="rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-bold text-blue-800"
                                >
                                    Grupo {{ team.group_code }}
                                </span>
                                <span
                                    v-if="team.seed_pot"
                                    class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-800"
                                >
                                    Pote {{ team.seed_pot }}
                                </span>
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ team.players.length }} jogador(es) no elenco
                            </p>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        <button
                            v-if="!isCompleted"
                            type="button"
                            class="inline-flex min-h-9 items-center gap-1 rounded-lg bg-slate-100 px-2.5 text-xs font-bold text-slate-700 hover:bg-slate-200"
                            :data-test="`adicionar-jogador-${team.id}`"
                            @click="openNewPlayer(team)"
                        >
                            <UserPlus class="size-3.5" />
                            Jogador
                        </button>
                        <button
                            type="button"
                            class="flex size-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100"
                            :aria-label="`Editar equipe ${team.name}`"
                            @click="openEditTeam(team)"
                        >
                            <Pencil class="size-4" />
                        </button>
                        <button
                            v-if="isDraft"
                            type="button"
                            class="flex size-9 items-center justify-center rounded-lg text-slate-500 hover:bg-red-50 hover:text-red-600"
                            :aria-label="`Remover equipe ${team.name}`"
                            @click="confirmRemoveTeam(team)"
                        >
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                </div>

                <div class="p-4">
                    <div
                        v-if="team.players.length"
                        class="overflow-x-auto rounded-xl border border-slate-100"
                    >
                        <table class="w-full text-left text-xs">
                            <thead
                                class="border-b border-slate-100 bg-slate-50 text-[11px] font-bold tracking-wider text-slate-500 uppercase"
                            >
                                <tr>
                                    <th class="px-3 py-2">#</th>
                                    <th class="px-3 py-2">Jogador</th>
                                    <th class="px-3 py-2">Posição</th>
                                    <th class="px-3 py-2">Goleiro</th>
                                    <th class="px-3 py-2 text-right">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr
                                    v-for="player in team.players"
                                    :key="player.id"
                                    class="hover:bg-slate-50/80"
                                    :data-championship-player-id="player.id"
                                >
                                    <td
                                        class="px-3 py-2 font-black text-slate-900"
                                    >
                                        #{{ player.jersey_number }}
                                    </td>
                                    <td
                                        class="px-3 py-2 font-semibold text-slate-900"
                                    >
                                        {{ player.name }}
                                    </td>
                                    <td class="px-3 py-2 text-slate-600">
                                        {{
                                            positionLabels[player.position] ??
                                            player.position
                                        }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <span
                                            v-if="
                                                player.is_goalkeeper ||
                                                player.position === 'goalkeeper'
                                            "
                                            class="inline-flex items-center rounded-md bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-800"
                                        >
                                            Goleiro
                                        </span>
                                        <span v-else class="text-slate-400"
                                            >—</span
                                        >
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <div
                                            v-if="!isCompleted"
                                            class="inline-flex items-center gap-1"
                                        >
                                            <button
                                                type="button"
                                                class="rounded p-1 text-slate-500 hover:bg-slate-100"
                                                :aria-label="`Editar jogador ${player.name}`"
                                                @click="
                                                    openEditPlayer(team, player)
                                                "
                                            >
                                                <Pencil class="size-3.5" />
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded p-1 text-slate-500 hover:bg-red-50 hover:text-red-600"
                                                :aria-label="`Remover jogador ${player.name}`"
                                                @click="
                                                    confirmRemovePlayer(
                                                        team,
                                                        player,
                                                    )
                                                "
                                            >
                                                <Trash2 class="size-3.5" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div
                        v-else
                        class="rounded-xl border border-dashed border-slate-200 bg-slate-50/60 p-4 text-center text-xs text-slate-500"
                    >
                        <Users class="mx-auto mb-1 size-5 text-slate-400" />
                        Nenhum atleta inscrito nesta equipe.
                        <button
                            v-if="!isCompleted"
                            type="button"
                            class="ml-1 font-bold text-slate-900 underline"
                            @click="openNewPlayer(team)"
                        >
                            Inscrever primeiro jogador
                        </button>
                    </div>
                </div>
            </section>
        </div>

        <div
            v-else
            class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center"
        >
            <Shield class="mx-auto size-10 text-slate-400" />
            <h3 class="mt-4 text-lg font-bold">Nenhuma equipe inscrita</h3>
            <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                Inscreva as equipes participantes com escudo, cores e seus
                respectivos elencos antes de sortear os grupos.
            </p>
            <button
                v-if="isDraft"
                type="button"
                class="mt-5 rounded-xl bg-slate-900 px-5 py-3 font-bold text-white"
                @click="openNewTeam"
            >
                Inscrever primeira equipe
            </button>
        </div>

        <!-- Team Modal -->
        <Teleport to="body">
            <div
                v-if="showTeamModal"
                class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/70 p-4"
                @click.self="closeTeamModal"
            >
                <form
                    class="mx-auto my-6 max-w-lg rounded-2xl bg-white p-5 shadow-2xl"
                    @submit.prevent="submitTeam"
                >
                    <div class="flex items-center justify-between">
                        <h2 class="text-xl font-black">
                            {{
                                editingTeam
                                    ? 'Editar equipe'
                                    : 'Inscrever equipe'
                            }}
                        </h2>
                        <button
                            type="button"
                            class="rounded-lg p-2 text-slate-500 hover:bg-slate-100"
                            aria-label="Fechar"
                            @click="closeTeamModal"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="mt-4 flex items-center gap-4">
                        <ChampionshipTeamBadge
                            :name="teamForm.name || 'Equipe'"
                            :short-name="teamForm.short_name"
                            :logo-url="logoPreview"
                            :primary-color="teamForm.primary_color"
                            :secondary-color="teamForm.secondary_color"
                            size="lg"
                        />
                        <div class="flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <label
                                    class="cursor-pointer rounded-xl bg-slate-100 px-3.5 py-2 text-xs font-bold text-slate-800 hover:bg-slate-200"
                                >
                                    Enviar escudo
                                    <input
                                        type="file"
                                        class="sr-only"
                                        accept="image/jpeg,image/png,image/webp"
                                        @change="onLogoChange"
                                    />
                                </label>
                                <button
                                    v-if="logoPreview"
                                    type="button"
                                    class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50"
                                    @click="clearLogo"
                                >
                                    Remover escudo
                                </button>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                JPG, PNG ou WebP até 4 MB (SVG não é permitido).
                            </p>
                            <p
                                v-if="teamForm.errors.logo"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ teamForm.errors.logo }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-[1fr_130px]">
                        <label class="block text-sm font-bold">
                            Nome da equipe *
                            <input
                                v-model="teamForm.name"
                                name="name"
                                type="text"
                                required
                                maxlength="100"
                                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 font-normal"
                                placeholder="Ex.: Tigres HC"
                            />
                            <span
                                v-if="teamForm.errors.name"
                                class="mt-1 block text-xs text-red-600"
                            >
                                {{ teamForm.errors.name }}
                            </span>
                        </label>

                        <label class="block text-sm font-bold">
                            Sigla
                            <input
                                v-model="teamForm.short_name"
                                name="short_name"
                                type="text"
                                maxlength="24"
                                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 font-normal"
                                placeholder="Ex.: TIG"
                            />
                            <span
                                v-if="teamForm.errors.short_name"
                                class="mt-1 block text-xs text-red-600"
                            >
                                {{ teamForm.errors.short_name }}
                            </span>
                        </label>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-4">
                        <label class="block text-sm font-bold">
                            Cor principal
                            <div
                                class="mt-1 flex items-center gap-2 rounded-xl border border-slate-300 p-2"
                            >
                                <input
                                    v-model="teamForm.primary_color"
                                    type="color"
                                    class="size-9 cursor-pointer rounded"
                                />
                                <span
                                    class="text-xs font-normal text-slate-700"
                                >
                                    {{ teamForm.primary_color }}
                                </span>
                            </div>
                            <span
                                v-if="teamForm.errors.primary_color"
                                class="mt-1 block text-xs text-red-600"
                            >
                                {{ teamForm.errors.primary_color }}
                            </span>
                        </label>

                        <label class="block text-sm font-bold">
                            Cor secundária
                            <div
                                class="mt-1 flex items-center gap-2 rounded-xl border border-slate-300 p-2"
                            >
                                <input
                                    v-model="teamForm.secondary_color"
                                    type="color"
                                    class="size-9 cursor-pointer rounded"
                                />
                                <span
                                    class="text-xs font-normal text-slate-700"
                                >
                                    {{ teamForm.secondary_color }}
                                </span>
                            </div>
                            <span
                                v-if="teamForm.errors.secondary_color"
                                class="mt-1 block text-xs text-red-600"
                            >
                                {{ teamForm.errors.secondary_color }}
                            </span>
                        </label>
                    </div>

                    <div v-if="isDraft" class="mt-4">
                        <label class="block text-sm font-bold">
                            Pote de sorteio (opcional)
                            <input
                                v-model.number="teamForm.seed_pot"
                                name="seed_pot"
                                type="number"
                                min="1"
                                max="16"
                                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 font-normal"
                                placeholder="Ex.: 1 para cabeça de chave"
                            />
                            <span
                                class="mt-1 block text-xs font-normal text-slate-500"
                            >
                                Equipes do mesmo pote são distribuídas em grupos
                                separados no sorteio.
                            </span>
                            <span
                                v-if="teamForm.errors.seed_pot"
                                class="mt-1 block text-xs text-red-600"
                            >
                                {{ teamForm.errors.seed_pot }}
                            </span>
                        </label>
                    </div>

                    <div class="mt-6 flex gap-3">
                        <button
                            type="button"
                            class="min-h-12 flex-1 rounded-xl border border-slate-300 font-bold text-slate-700"
                            @click="closeTeamModal"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            class="min-h-12 flex-1 rounded-xl bg-[var(--team-primary)] font-bold text-white disabled:opacity-50"
                            data-test="salvar-equipe"
                            :disabled="teamForm.processing"
                        >
                            {{
                                teamForm.processing
                                    ? 'Salvando…'
                                    : 'Salvar equipe'
                            }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>

        <!-- Player Modal -->
        <Teleport to="body">
            <div
                v-if="showPlayerModal && activeTeamForPlayer"
                class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/70 p-4"
                @click.self="closePlayerModal"
            >
                <form
                    class="mx-auto my-6 max-w-md rounded-2xl bg-white p-5 shadow-2xl"
                    @submit.prevent="submitPlayer"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <p
                                class="text-xs font-bold tracking-wider text-slate-500 uppercase"
                            >
                                {{ activeTeamForPlayer.name }}
                            </p>
                            <h2 class="text-xl font-black">
                                {{
                                    editingPlayer
                                        ? 'Editar jogador'
                                        : 'Inscrever jogador'
                                }}
                            </h2>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-2 text-slate-500 hover:bg-slate-100"
                            aria-label="Fechar"
                            @click="closePlayerModal"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-[1fr_110px]">
                        <label class="block text-sm font-bold">
                            Nome do jogador *
                            <input
                                v-model="playerForm.name"
                                name="name"
                                type="text"
                                required
                                maxlength="100"
                                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 font-normal"
                                placeholder="Nome do atleta"
                            />
                            <span
                                v-if="playerForm.errors.name"
                                class="mt-1 block text-xs text-red-600"
                            >
                                {{ playerForm.errors.name }}
                            </span>
                        </label>

                        <label class="block text-sm font-bold">
                            Camisa *
                            <input
                                v-model.number="playerForm.jersey_number"
                                name="jersey_number"
                                type="number"
                                required
                                min="0"
                                max="999"
                                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 font-normal"
                                placeholder="10"
                            />
                            <span
                                v-if="playerForm.errors.jersey_number"
                                class="mt-1 block text-xs text-red-600"
                            >
                                {{ playerForm.errors.jersey_number }}
                            </span>
                        </label>
                    </div>

                    <div class="mt-4">
                        <label class="block text-sm font-bold">
                            Posição
                            <select
                                v-model="playerForm.position"
                                name="position"
                                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 font-normal"
                                @change="onPositionChange"
                            >
                                <option value="universal">Universal</option>
                                <option value="forward">Atacante</option>
                                <option value="defender">Defensor</option>
                                <option value="goalkeeper">Goleiro</option>
                            </select>
                            <span
                                v-if="playerForm.errors.position"
                                class="mt-1 block text-xs text-red-600"
                            >
                                {{ playerForm.errors.position }}
                            </span>
                        </label>
                    </div>

                    <label
                        class="mt-4 flex cursor-pointer items-center gap-3 rounded-xl bg-slate-50 p-3 text-sm font-bold"
                    >
                        <input
                            v-model="playerForm.is_goalkeeper"
                            type="checkbox"
                            class="size-5 accent-emerald-700"
                        />
                        Atua como goleiro
                    </label>

                    <div class="mt-6 flex gap-3">
                        <button
                            type="button"
                            class="min-h-12 flex-1 rounded-xl border border-slate-300 font-bold text-slate-700"
                            @click="closePlayerModal"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            class="min-h-12 flex-1 rounded-xl bg-[var(--team-primary)] font-bold text-white disabled:opacity-50"
                            data-test="salvar-jogador-campeonato"
                            :disabled="playerForm.processing"
                        >
                            {{
                                playerForm.processing
                                    ? 'Salvando…'
                                    : 'Salvar jogador'
                            }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
    </div>
</template>
