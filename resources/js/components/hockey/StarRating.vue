<script setup lang="ts">
import { Star } from 'lucide-vue-next';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: number;
        readonly?: boolean;
        compact?: boolean;
    }>(),
    { readonly: false, compact: false },
);
const emit = defineEmits<{ 'update:modelValue': [value: number] }>();
const stars = computed(() => [1, 2, 3, 4, 5]);
const label = (value: number) => `${value.toLocaleString('pt-BR')} estrelas`;
function fraction(value: number): string {
    const rest = props.modelValue - value + 1;
    return rest >= 1 ? '100%' : rest === 0.5 ? '50%' : '0%';
}
</script>

<template>
    <div
        class="inline-flex w-fit items-center gap-0.5"
        role="radiogroup"
        aria-label="Nível em estrelas"
        :aria-readonly="readonly"
    >
        <div
            v-for="star in stars"
            :key="star"
            class="relative"
            :class="compact ? 'size-4' : 'size-6'"
        >
            <Star
                class="absolute inset-0 size-full fill-slate-200 text-slate-300"
            />
            <div
                class="absolute inset-y-0 left-0 overflow-hidden"
                :style="{ width: fraction(star) }"
            >
                <Star
                    class="fill-amber-400 text-amber-500"
                    :class="compact ? 'size-4' : 'size-6'"
                />
            </div>
            <template v-if="!readonly">
                <button
                    type="button"
                    class="absolute inset-y-0 left-0 z-10 w-1/2"
                    :title="label(star - 0.5)"
                    :aria-label="label(star - 0.5)"
                    :aria-checked="modelValue === star - 0.5"
                    role="radio"
                    @click="emit('update:modelValue', star - 0.5)"
                />
                <button
                    type="button"
                    class="absolute inset-y-0 right-0 z-10 w-1/2"
                    :title="label(star)"
                    :aria-label="label(star)"
                    :aria-checked="modelValue === star"
                    role="radio"
                    @click="emit('update:modelValue', star)"
                />
            </template>
        </div>
        <span
            v-if="!readonly"
            class="ml-2 text-sm font-black text-slate-700 dark:text-slate-200"
            >{{
                modelValue.toLocaleString('pt-BR', { minimumFractionDigits: 1 })
            }}</span
        >
    </div>
</template>

<style scoped>
@media (pointer: coarse) {
    button::after {
        content: '';
        position: absolute;
        inset: -10px -1px;
    }
}
</style>
