export interface InertiaPage {
    props?: Record<string, unknown>;
    version?: string | null;
}

export function parseInertiaPage(
    raw: string | null | undefined,
): InertiaPage | null {
    if (!raw) return null;
    try {
        const page: unknown = JSON.parse(raw);
        return page && typeof page === 'object' && !Array.isArray(page)
            ? (page as InertiaPage)
            : null;
    } catch {
        return null;
    }
}

/** Lê tanto o script do Inertia v3 quanto o atributo do formato legado. */
export function initialInertiaPage(
    documentLike: Pick<Document, 'querySelector'> = document,
): InertiaPage | null {
    const script = documentLike.querySelector<HTMLScriptElement>(
        'script[data-page][type="application/json"]',
    );
    if (script) return parseInertiaPage(script.textContent);

    return parseInertiaPage(
        documentLike.querySelector<HTMLElement>('[data-page]')?.dataset.page,
    );
}
