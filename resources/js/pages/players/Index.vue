<script setup lang="ts">
import PlayerPhoto from '@/components/hockey/PlayerPhoto.vue';
import StarRating from '@/components/hockey/StarRating.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Search, Trash2, Users, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import type { Player } from '@/types/hockey';

const props = defineProps<{ players: Player[]; presentCount: number }>();
const editing = ref<Player | null>(null);
const showForm = ref(false);
const search = ref('');
const photoPreview = ref<string | null>(null);
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
const filteredPlayers = computed(() =>
    props.players.filter((player) =>
        `${player.name} ${player.jersey_number}`
            .toLowerCase()
            .includes(search.value.toLowerCase()),
    ),
);
function openNew() {
    editing.value = null;
    showForm.value = true;
    photoPreview.value = null;
    form.reset();
    form.jersey_number = '';
    form.level = 3;
}
function openEdit(player: Player) {
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
    showForm.value = false;
    form.clearErrors();
}
function preview(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] || null;
    form.photo = file;
    form.remove_photo = false;
    photoPreview.value = file ? URL.createObjectURL(file) : null;
}
function save() {
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
    router.post('/presenca', { present }, { preserveScroll: true });
}
function toggle(player: Player) {
    router.patch(
        `/jogadores/${player.id}/presenca`,
        { present: !player.present },
        { preserveScroll: true },
    );
}
function remove(player: Player) {
    if (
        window.confirm(
            `Remover ${player.name}? Os gols do histórico serão preservados.`,
        )
    )
        router.delete(`/jogadores/${player.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Jogadores e presença" />
    <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
            <p
                class="text-xs font-bold tracking-widest text-slate-500 uppercase dark:text-slate-400"
            >
                Elenco do time
            </p>
            <h1 class="mt-1 text-2xl font-black">Jogadores e presença</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                <strong class="text-green-700">{{ presentCount }}</strong> de
                {{ players.length }} entrarão no sorteio
            </p>
        </div>
        <button
            class="flex min-h-12 items-center gap-2 rounded-xl bg-[var(--team-primary)] px-4 font-bold text-[var(--team-primary-text)]"
            data-test="novo-jogador"
            @click="openNew"
        >
            <Plus class="size-5" />Novo jogador
        </button>
    </div>
    <section
        class="mb-5 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
    >
        <div class="flex items-center justify-between gap-2">
            <h2 class="font-bold">Quem veio hoje?</h2>
            <div class="flex gap-2">
                <button
                    class="min-h-10 rounded-lg bg-green-100 px-3 text-xs font-bold text-green-800"
                    @click="presenceAll(true)"
                >
                    Marcar todos</button
                ><button
                    class="min-h-10 rounded-lg bg-slate-100 px-3 text-xs font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-200"
                    @click="presenceAll(false)"
                >
                    Limpar
                </button>
            </div>
        </div>
        <div class="relative mt-4">
            <Search
                class="absolute top-3.5 left-3 size-5 text-slate-400"
            /><input
                v-model="search"
                type="search"
                class="min-h-12 w-full rounded-xl border border-slate-300 pr-3 pl-10 dark:border-slate-600"
                placeholder="Buscar nome ou número"
                aria-label="Buscar jogador"
            />
        </div>
    </section>
    <div
        v-if="filteredPlayers.length"
        class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
    >
        <article
            v-for="player in filteredPlayers"
            :key="player.id"
            class="rounded-xl border bg-white transition-colors dark:bg-slate-900"
            :class="
                player.present
                    ? 'border-green-300'
                    : 'border-slate-200 bg-slate-50/60 dark:border-slate-700 dark:bg-slate-800/40'
            "
            :data-player-id="player.id"
        >
            <div class="flex items-center gap-1 p-2.5">
                <label
                    class="flex min-w-0 flex-1 cursor-pointer items-center gap-2.5"
                >
                    <input
                        type="checkbox"
                        class="size-5 shrink-0 accent-green-700"
                        :checked="player.present"
                        :aria-label="`Presença de ${player.name}`"
                        @change="toggle(player)"
                    />
                    <PlayerPhoto :player="player" size="size-12" />
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate text-sm font-bold">
                            {{ player.name }}
                        </h2>
                        <span
                            class="text-xs"
                            :class="
                                player.present
                                    ? 'font-bold text-green-700'
                                    : 'text-slate-500 dark:text-slate-400'
                            "
                        >
                            {{ player.present ? 'Presente' : 'Ausente' }}
                        </span>
                    </div>
                    <strong
                        class="shrink-0 text-3xl leading-none font-black tracking-tight text-slate-800 dark:text-slate-100"
                        >#{{ player.jersey_number }}</strong
                    >
                </label>
                <div class="flex shrink-0">
                    <button
                        class="flex size-8 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
                        :aria-label="`Editar ${player.name}`"
                        @click="openEdit(player)"
                    >
                        <Pencil class="size-4" />
                    </button>
                    <button
                        class="flex size-8 items-center justify-center rounded-lg text-slate-500 hover:bg-red-50 hover:text-red-600 dark:text-slate-400"
                        :aria-label="`Remover ${player.name}`"
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
        class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center dark:border-slate-600 dark:bg-slate-900"
    >
        <Users class="mx-auto size-10 text-slate-400" />
        <h2 class="mt-4 text-xl font-bold">Monte o elenco</h2>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            Cadastre nome, número, foto e nível dos jogadores.
        </p>
        <button
            class="mt-5 rounded-xl bg-slate-900 px-5 py-3 font-bold text-white dark:bg-slate-700"
            @click="openNew"
        >
            Cadastrar primeiro jogador
        </button>
    </div>
    <p v-else class="py-8 text-center text-slate-500 dark:text-slate-400">
        Nenhum jogador encontrado.
    </p>
    <Teleport to="body"
        ><div
            v-if="showForm"
            class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/70 p-4"
            @click.self="closeForm"
        >
            <form
                class="mx-auto my-5 max-w-lg rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900"
                @submit.prevent="save"
            >
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-black">
                        {{ editing ? 'Editar jogador' : 'Novo jogador' }}
                    </h2>
                    <button
                        type="button"
                        class="p-2"
                        aria-label="Fechar"
                        @click="closeForm"
                    >
                        <X />
                    </button>
                </div>
                <div class="mt-5 flex items-center gap-4">
                    <img
                        v-if="photoPreview"
                        :src="photoPreview"
                        alt="Prévia da foto"
                        class="size-20 rounded-2xl object-cover"
                    /><span
                        v-else
                        class="flex size-20 items-center justify-center rounded-2xl bg-slate-100 text-2xl font-black text-slate-400 dark:bg-slate-800"
                        >{{ form.jersey_number || '?' }}</span
                    >
                    <div>
                        <label
                            class="cursor-pointer rounded-xl bg-slate-100 px-4 py-3 text-sm font-bold dark:bg-slate-800"
                            >Adicionar foto<input
                                type="file"
                                class="sr-only"
                                accept="image/jpeg,image/png,image/webp"
                                @change="preview"
                        /></label>
                        <p
                            class="mt-1 text-xs text-slate-500 dark:text-slate-400"
                        >
                            JPG, PNG ou WebP, até 4 MB
                        </p>
                    </div>
                </div>
                <p v-if="form.errors.photo" class="mt-2 text-sm text-red-600">
                    {{ form.errors.photo }}
                </p>
                <div class="mt-5 grid gap-4 sm:grid-cols-[1fr_120px]">
                    <label class="text-sm font-bold"
                        >Nome<input
                            v-model="form.name"
                            name="name"
                            required
                            maxlength="100"
                            class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-600"
                            placeholder="Nome do jogador"
                        /><span
                            v-if="form.errors.name"
                            class="text-xs text-red-600"
                            >{{ form.errors.name }}</span
                        ></label
                    ><label class="text-sm font-bold"
                        >Número<input
                            v-model.number="form.jersey_number"
                            name="jersey_number"
                            required
                            type="number"
                            min="0"
                            max="999"
                            class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-600"
                        /><span
                            v-if="form.errors.jersey_number"
                            class="text-xs text-red-600"
                            >{{ form.errors.jersey_number }}</span
                        ></label
                    >
                </div>
                <fieldset class="mt-5">
                    <legend class="mb-2 text-sm font-bold">
                        Nível técnico
                    </legend>
                    <StarRating v-model="form.level" /><input
                        type="hidden"
                        name="level"
                        :value="form.level"
                    />
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Toque na metade esquerda ou direita da estrela.
                    </p>
                </fieldset>
                <label
                    class="mt-5 flex items-center gap-3 rounded-xl bg-slate-50 p-3 text-sm font-bold dark:bg-slate-800/50"
                    ><input
                        v-model="form.present"
                        type="checkbox"
                        class="size-5"
                    />Disponível para o sorteio</label
                >
                <div class="mt-5 flex gap-3">
                    <button
                        type="button"
                        class="min-h-12 flex-1 rounded-xl border border-slate-300 font-bold dark:border-slate-600"
                        @click="closeForm"
                    >
                        Cancelar</button
                    ><button
                        class="min-h-12 flex-1 rounded-xl bg-[var(--team-primary)] font-bold text-[var(--team-primary-text)] disabled:opacity-50"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Salvando…' : 'Salvar jogador' }}
                    </button>
                </div>
            </form>
        </div></Teleport
    >
</template>
