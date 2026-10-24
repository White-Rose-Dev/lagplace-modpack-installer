import http from '@/api/http';

export interface ModpackHit {
    id: string;
    slug: string;
    url?: string | null;
    title: string;
    description: string;
    icon: string | null;
    downloads: number;
    loaders: string[];
    versions: string[];
}

export interface ProviderInfo {
    id: string;
    name: string;
    enabled: boolean;
}

export interface ModpackSearch {
    provider: string;
    providers: ProviderInfo[];
    page: number;
    per_page: number;
    total: number;
    hits: ModpackHit[];
}

export interface ModpackVersion {
    id: string;
    name: string;
    versionNumber: string;
    gameVersions: string[];
    loaders: string[];
    datePublished: string;
}

export interface InstallState {
    stage: string;
    percent: number;
    pack: string;
    detail: string | null;
    done: number;
    total: number;
    backup_uuid: string | null;
    error: string | null;
    wiped: boolean;
    started_at: number;
    updated_at: number;
}

export const TERMINAL_STAGES = ['done', 'failed', 'cancelled'];

export const isActive = (state: InstallState | null): boolean => !!state && !TERMINAL_STAGES.includes(state.stage);

const base = (uuid: string) => `/api/client/servers/${uuid}/modpacks`;

export const searchModpacks = (uuid: string, provider: string, q: string, page: number, perPage: number): Promise<ModpackSearch> =>
    http.get(base(uuid), { params: { provider, q: q || undefined, page, per_page: perPage } }).then(({ data }) => data);

export interface InstalledModpack {
    pack: string;
    minecraft: string | null;
    loader: string | null;
    installed_at: string | null;
}

export const getInstalledModpack = (uuid: string): Promise<InstalledModpack | null> =>
    http.get(`${base(uuid)}/installed`).then(({ data }) => data.installed ?? null);

export const getModpackVersions = (uuid: string, provider: string, projectId: string): Promise<ModpackVersion[]> =>
    http.get(`${base(uuid)}/${encodeURIComponent(projectId)}/versions`, { params: { provider } }).then(({ data }) => data.versions);

export const installModpack = (uuid: string, provider: string, versionId: string): Promise<InstallState> =>
    http.post(`${base(uuid)}/install`, { provider, version_id: versionId, accept_eula: true }).then(({ data }) => data.state);

export const getInstallStatus = (uuid: string): Promise<InstallState | null> =>
    http.get(`${base(uuid)}/status`).then(({ data }) => data.state ?? null);

export const cancelInstall = (uuid: string): Promise<void> => http.post(`${base(uuid)}/cancel`).then(() => undefined);

/** Mensagem legível de um erro do axios (as nossas 422/409 trazem `message`; as de validação do Laravel trazem `errors[0].detail`). */
export const errorMessage = (e: any): string => {
    if (e?.response?.status === 403) return 'Only the server owner can do this.';
    if (e?.response?.status === 429) return 'Too many attempts. Wait a minute and try again.';

    return e?.response?.data?.message || e?.response?.data?.errors?.[0]?.detail || 'Something went wrong. Please try again.';
};
