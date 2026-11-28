import type { Directive } from 'vue';
import type { PageProps } from '@inertiajs/core';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: PageProps & {
            name: string;
            canRegister?: boolean;
            team: {
                id: number;
                name: string;
                logo_url: string | null;
                primary_color: string;
                secondary_color: string;
                primary_text: string;
            };
            flash: { success?: string };
        };
    }
}
