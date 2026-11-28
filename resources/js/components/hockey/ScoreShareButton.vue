<script setup lang="ts">
import { useToast } from '@/composables/useToast';
import type { MatchGoalProp, PublicMatchProp } from '@/types/hockey';
import { Share2 } from 'lucide-vue-next';

const props = defineProps<{
    match: PublicMatchProp;
    goals: MatchGoalProp[];
}>();

const toast = useToast();

function roundedRect(
    context: CanvasRenderingContext2D,
    x: number,
    y: number,
    width: number,
    height: number,
    radius: number,
) {
    context.beginPath();
    context.moveTo(x + radius, y);
    context.arcTo(x + width, y, x + width, y + height, radius);
    context.arcTo(x + width, y + height, x, y + height, radius);
    context.arcTo(x, y + height, x, y, radius);
    context.arcTo(x, y, x + width, y, radius);
    context.closePath();
}

function truncate(value: string, max = 28): string {
    return value.length > max ? `${value.slice(0, max - 1)}…` : value;
}

function line(
    context: CanvasRenderingContext2D,
    text: string,
    x: number,
    y: number,
    maxWidth: number,
) {
    context.fillText(truncate(text), x, y, maxWidth);
}

async function shareScore() {
    const canvas = document.createElement('canvas');
    canvas.width = 1080;
    canvas.height = 1350;
    const context = canvas.getContext('2d');
    if (!context) return;

    const { home, away } = props.match.roster;
    const gradient = context.createLinearGradient(0, 0, 1080, 1350);
    gradient.addColorStop(0, '#08111f');
    gradient.addColorStop(1, '#111c31');
    context.fillStyle = gradient;
    context.fillRect(0, 0, 1080, 1350);
    context.globalAlpha = 0.85;
    context.fillStyle = home.color;
    context.fillRect(0, 0, 145, 1350);
    context.fillStyle = away.color;
    context.fillRect(935, 0, 145, 1350);
    context.globalAlpha = 1;

    context.fillStyle = '#f8fafc';
    context.font = '700 38px system-ui, sans-serif';
    context.textAlign = 'center';
    context.fillText('HOCKEY GAME', 540, 95);
    context.fillStyle = '#94a3b8';
    context.font = '600 25px system-ui, sans-serif';
    context.fillText(props.match.finished_at ?? props.match.started_at ?? 'Partida salva', 540, 142);

    roundedRect(context, 105, 205, 870, 385, 34);
    context.fillStyle = 'rgba(255,255,255,0.07)';
    context.fill();
    context.fillStyle = '#f8fafc';
    context.font = '800 43px system-ui, sans-serif';
    context.textAlign = 'center';
    line(context, home.name, 285, 305, 270);
    line(context, away.name, 795, 305, 270);
    context.fillStyle = home.color;
    context.fillRect(150, 345, 270, 12);
    context.fillStyle = away.color;
    context.fillRect(660, 345, 270, 12);
    context.fillStyle = '#ffffff';
    context.font = '800 150px system-ui, sans-serif';
    context.fillText(`${props.match.home_score}  ×  ${props.match.away_score}`, 540, 490);

    context.textAlign = 'left';
    context.fillStyle = '#f8fafc';
    context.font = '800 34px system-ui, sans-serif';
    context.fillText('Gols e assistências', 125, 690);
    const shownGoals = props.goals.slice(0, 8);
    context.font = '600 29px system-ui, sans-serif';
    shownGoals.forEach((goal, index) => {
        const y = 755 + index * 64;
        context.fillStyle = props.match.roster[goal.team].color;
        context.fillRect(125, y - 29, 10, 38);
        context.fillStyle = '#f8fafc';
        const assist = goal.assist_name ? ` · A: ${goal.assist_name}` : '';
        line(context, `${goal.scorer_name}${assist}`, 155, y, 780);
    });
    if (!shownGoals.length) {
        context.fillStyle = '#cbd5e1';
        context.fillText('Nenhum gol registrado', 125, 755);
    }
    context.textAlign = 'center';
    context.fillStyle = '#94a3b8';
    context.font = '600 24px system-ui, sans-serif';
    context.fillText('hockey game · placar compartilhável', 540, 1260);

    const blob = await new Promise<Blob | null>((resolve) =>
        canvas.toBlob(resolve, 'image/png'),
    );
    if (!blob) {
        toast.error('Não foi possível gerar a imagem do placar.');
        return;
    }
    const filename = 'placar-hockey-game.png';
    const file = new File([blob], filename, { type: 'image/png' });
    const shareData = { files: [file], title: 'Placar - Hockey Game' };
    if (navigator.share && navigator.canShare?.(shareData)) {
        try {
            await navigator.share(shareData);
            return;
        } catch (error) {
            if ((error as DOMException).name === 'AbortError') return;
        }
    }
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.click();
    URL.revokeObjectURL(url);
    toast.success('Imagem do placar baixada.');
}
</script>

<template>
    <button
        type="button"
        class="inline-flex min-h-10 items-center gap-1.5 rounded-xl border border-white/20 bg-white/10 px-3 text-xs font-bold text-white transition hover:bg-white/20"
        @click="shareScore"
    >
        <Share2 class="size-3.5" />
        Compartilhar placar
    </button>
</template>
