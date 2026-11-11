<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Calendar,
    MapPin,
    Plus,
    Shield,
    Trophy,
    Users,
    X,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import type {
    ChampionshipProp,
    ChampionshipRulesProp,
    ChampionshipStatus,
} from '@/types/championship';

defineProps<{
    championships: ChampionshipProp[];
    default_rules: ChampionshipRulesProp;
}>();

const page = usePage();
const pageErrors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);

const showCreateModal = ref(false);

const form = useForm<{
    name: string;
    city: string;
    venue: string;
    starts_on: string;
    ends_on: string;
}>({
    name: '',
    city: '',
    venue: '',
    starts_on: '',
    ends_on: '',
});

const statusLabels: Record<ChampionshipStatus, string> = {
    draft: 'Rascunho',
    group_stage: 'Fase de grupos',
    knockout_stage: 'Mata-mata',
    completed: 'Encerrado',
};

const statusClasses: Record<ChampionshipStatus, string> = {
    draft: 'bg-amber-100 text-amber-800 border-amber-200',
    group_stage: 'bg-blue-100 text-blue-800 border-blue-200',
    knockout_stage: 'bg-purple-100 text-purple-800 border-purple-200',
    completed: 'bg-green-100 text-green-800 border-green-200',
};

function formatDate(dateStr: string | null): string {
    if (!dateStr) return '';
    const parts = dateStr.slice(0, 10).split('-');
    if (parts.length !== 3) return dateStr;
    return `${parts[2]}/${parts[1]}/${parts[0]}`;
}

function formatDateRange(
    startsOn: string | null,
    endsOn: string | null,
): string {
    if (startsOn && endsOn) {
        return `${formatDate(startsOn)} a ${formatDate(endsOn)}`;
    }
    if (startsOn) {
        return `Início em ${formatDate(startsOn)}`;
    }
    if (endsOn) {
        return `Até ${formatDate(endsOn)}`;
    }
    return 'Datas a definir';
}

function openCreate() {
    form.reset();
    form.clearErrors();
    showCreateModal.value = true;
}

function closeCreate() {
    showCreateModal.value = false;
    form.clearErrors();
}

function submitCreate() {
    form.post('/campeonatos', {
        preserveScroll: true,
        onSuccess: () => closeCreate(),
    });
}
</script>

<template>
    <Head title="Campeonatos" />

    <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
            <p
                class="text-xs font-bold tracking-widest text-slate-500 uppercase"
            >
                Torneios e ligas
            </p>
            <h1 class="mt-1 text-2xl font-black">Campeonatos</h1>
            <p class="mt-1 text-sm text-slate-500">
                Organize campeonatos municipais com inscrição de equipes, grupos
                e mata-mata.
            </p>
        </div>
        <button
            type="button"
            class="flex min-h-12 items-center gap-2 rounded-xl bg-[var(--team-primary)] px-4 font-bold text-white"
            data-test="novo-campeonato"
            @click="openCreate"
        >
            <Plus class="size-5" />Novo campeonato
        </button>
    </div>

    <div
        v-if="pageErrors.championship"
        class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800"
        role="alert"
    >
        {{ pageErrors.championship }}
    </div>

    <div v-if="championships.length" class="grid gap-4 md:grid-cols-2">
        <article
            v-for="championship in championships"
            :key="championship.id"
            class="flex flex-col justify-between rounded-2xl border border-slate-200 bg-white p-5 transition hover:border-slate-300 hover:shadow-sm"
            :data-championship-id="championship.id"
        >
            <div>
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <Link
                            :href="`/campeonatos/${championship.id}`"
                            class="block truncate text-lg font-black text-slate-900 hover:underline"
                        >
                            {{ championship.name }}
                        </Link>
                        <p
                            class="mt-1 flex flex-wrap items-center gap-1.5 text-xs font-semibold text-slate-500"
                        >
                            <MapPin class="size-3.5 shrink-0" />
                            <span>{{ championship.city }}</span>
                            <span v-if="championship.venue"
                                >· {{ championship.venue }}</span
                            >
                        </p>
                    </div>
                    <span
                        class="shrink-0 rounded-full border px-2.5 py-1 text-xs font-bold"
                        :class="statusClasses[championship.status]"
                    >
                        {{ statusLabels[championship.status] }}
                    </span>
                </div>

                <div
                    class="mt-4 flex flex-wrap items-center gap-3 text-xs text-slate-600"
                >
                    <span
                        class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-2.5 py-1 font-semibold"
                    >
                        <Calendar class="size-3.5 text-slate-500" />
                        {{
                            formatDateRange(
                                championship.starts_on,
                                championship.ends_on,
                            )
                        }}
                    </span>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-2.5 py-1 font-semibold"
                    >
                        <Shield class="size-3.5 text-slate-500" />
                        {{ championship.teams_count ?? 0 }} equipes
                    </span>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-2.5 py-1 font-semibold"
                    >
                        <Users class="size-3.5 text-slate-500" />
                        {{ championship.games_count ?? 0 }} jogos
                    </span>
                </div>
            </div>

            <div
                class="mt-4 flex items-center justify-between gap-2 border-t border-slate-100 pt-3"
            >
                <span class="text-xs text-slate-500">
                    {{ championship.rules.groups_count }} grupo(s) ·
                    {{ championship.rules.qualifiers_per_group }}
                    classificado(s)/grupo
                </span>
                <div class="flex items-center gap-2">
                    <Link
                        :href="`/campeonatos/${championship.id}/resumo`"
                        class="rounded-lg px-2.5 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-100"
                    >
                        Resumo
                    </Link>
                    <Link
                        :href="`/campeonatos/${championship.id}`"
                        class="inline-flex items-center gap-1 rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-bold text-white hover:bg-slate-800"
                    >
                        Gerenciar
                        <ArrowRight class="size-3.5" />
                    </Link>
                </div>
            </div>
        </article>
    </div>

    <div
        v-else
        class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center"
    >
        <Trophy class="mx-auto size-10 text-slate-400" />
        <h2 class="mt-4 text-xl font-bold">Nenhum campeonato cadastrado</h2>
        <p class="mx-auto mt-2 max-w-md text-sm text-slate-500">
            Crie um campeonato municipal, inscreva as equipes participantes,
            ajuste as regras ao regulamento local e acompanhe a tabela e o
            mata-mata.
        </p>
        <button
            type="button"
            class="mt-5 rounded-xl bg-slate-900 px-5 py-3 font-bold text-white"
            data-test="criar-primeiro-campeonato"
            @click="openCreate"
        >
            Criar primeiro campeonato
        </button>
    </div>

    <Teleport to="body">
        <div
            v-if="showCreateModal"
            class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/70 p-4"
            @click.self="closeCreate"
        >
            <form
                class="mx-auto my-6 max-w-lg rounded-2xl bg-white p-5 shadow-2xl"
                @submit.prevent="submitCreate"
            >
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-black">Novo campeonato</h2>
                    <button
                        type="button"
                        class="rounded-lg p-2 text-slate-500 hover:bg-slate-100"
                        aria-label="Fechar"
                        @click="closeCreate"
                    >
                        <X class="size-5" />
                    </button>
                </div>

                <p class="mt-1 text-xs text-slate-500">
                    Informe os dados básicos. As regras detalhadas poderão ser
                    ajustadas antes do sorteio dos grupos.
                </p>

                <div class="mt-4 space-y-4">
                    <label class="block text-sm font-bold">
                        Nome do campeonato *
                        <input
                            v-model="form.name"
                            name="name"
                            type="text"
                            required
                            maxlength="120"
                            class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 font-normal"
                            placeholder="Ex.: Copa Municipal de Hóquei"
                        />
                        <span
                            v-if="form.errors.name"
                            class="mt-1 block text-xs text-red-600"
                        >
                            {{ form.errors.name }}
                        </span>
                    </label>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block text-sm font-bold">
                            Cidade *
                            <input
                                v-model="form.city"
                                name="city"
                                type="text"
                                required
                                maxlength="100"
                                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 font-normal"
                                placeholder="Ex.: São Paulo"
                            />
                            <span
                                v-if="form.errors.city"
                                class="mt-1 block text-xs text-red-600"
                            >
                                {{ form.errors.city }}
                            </span>
                        </label>

                        <label class="block text-sm font-bold">
                            Local / Ginásio
                            <input
                                v-model="form.venue"
                                name="venue"
                                type="text"
                                maxlength="140"
                                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 font-normal"
                                placeholder="Ex.: Ginásio Municipal"
                            />
                            <span
                                v-if="form.errors.venue"
                                class="mt-1 block text-xs text-red-600"
                            >
                                {{ form.errors.venue }}
                            </span>
                        </label>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block text-sm font-bold">
                            Data de início
                            <input
                                v-model="form.starts_on"
                                name="starts_on"
                                type="date"
                                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 font-normal"
                            />
                            <span
                                v-if="form.errors.starts_on"
                                class="mt-1 block text-xs text-red-600"
                            >
                                {{ form.errors.starts_on }}
                            </span>
                        </label>

                        <label class="block text-sm font-bold">
                            Data de término
                            <input
                                v-model="form.ends_on"
                                name="ends_on"
                                type="date"
                                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 font-normal"
                            />
                            <span
                                v-if="form.errors.ends_on"
                                class="mt-1 block text-xs text-red-600"
                            >
                                {{ form.errors.ends_on }}
                            </span>
                        </label>
                    </div>
                </div>

                <div class="mt-6 flex gap-3">
                    <button
                        type="button"
                        class="min-h-12 flex-1 rounded-xl border border-slate-300 font-bold text-slate-700"
                        @click="closeCreate"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        class="min-h-12 flex-1 rounded-xl bg-[var(--team-primary)] font-bold text-white disabled:opacity-50"
                        data-test="salvar-campeonato"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Criando…' : 'Criar campeonato' }}
                    </button>
                </div>
            </form>
        </div>
    </Teleport>
</template>
