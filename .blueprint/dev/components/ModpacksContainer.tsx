import React, { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { css } from 'styled-components/macro';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Spinner from '@/components/elements/Spinner';
import { Button } from '@/components/elements/button';
import { ServerContext } from '@/state/server';
import { useStoreState } from '@/state/hooks';
import ModpackCard from './ModpackCard';
import InstallModal from './InstallModal';
import ProgressModal from './ProgressModal';
import {
    errorMessage,
    getInstallStatus,
    InstallState,
    isActive,
    ModpackHit,
    ModpackSearch,
    ProviderInfo,
    searchModpacks,
} from './modpacks';

const strip = css`
    border: 1px solid rgba(0, 202, 150, 0.14);
    border-left: 4px solid rgba(0, 202, 150, 0.5);
    background: rgba(3, 28, 34, 0.45);
    border-radius: 0.65rem;
`;

// Duas colunas em ecrãs largos (mais modpacks de uma vez); uma só em ecrãs estreitos.
const grid = css`
    display: grid;
    gap: 0.6rem;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    @media (max-width: 1200px) {
        grid-template-columns: minmax(0, 1fr);
    }
`;

const DEFAULT_PROVIDERS: ProviderInfo[] = [
    { id: 'modrinth', name: 'Modrinth', enabled: true },
    { id: 'curseforge', name: 'CurseForge', enabled: false },
    { id: 'ftb', name: 'Feed The Beast', enabled: true },
    { id: 'atlauncher', name: 'ATLauncher', enabled: false },
    { id: 'technic', name: 'Technic', enabled: false },
];

const Notice = ({ children }: { children: React.ReactNode }) => (
    <ServerContentBlock title={'Modpacks'}>
        <div css={[strip, tw`p-4 text-sm text-neutral-200`]}>{children}</div>
    </ServerContentBlock>
);

const Content = ({ uuid }: { uuid: string }) => {
    const [typed, setTyped] = useState('');
    const [query, setQuery] = useState('');
    const [page, setPage] = useState(1);
    const [provider, setProvider] = useState('modrinth');
    const [results, setResults] = useState<ModpackSearch | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [picked, setPicked] = useState<ModpackHit | null>(null);
    const [install, setInstall] = useState<InstallState | null>(null);
    const [showProgress, setShowProgress] = useState(false);
    const [dismissed, setDismissed] = useState(0);
    const memoryMb = ServerContext.useStoreState((state) => state.server.data!.limits.memory);

    useEffect(() => {
        let alive = true;
        setLoading(true);
        setError('');
        searchModpacks(uuid, provider, query, page)
            .then((r) => alive && setResults(r))
            .catch((e) => alive && setError(errorMessage(e)))
            .then(() => alive && setLoading(false));

        return () => {
            alive = false;
        };
    }, [uuid, provider, query, page]);

    useEffect(() => {
        let alive = true;
        getInstallStatus(uuid)
            .then((s) => {
                if (!alive) return;
                setInstall(s);
                if (isActive(s)) setShowProgress(true);
            })
            .catch(() => undefined);

        return () => {
            alive = false;
        };
    }, [uuid]);

    const active = isActive(install);
    useEffect(() => {
        if (!active) return;
        const timer = setInterval(() => {
            getInstallStatus(uuid)
                .then((s) => setInstall(s))
                .catch(() => undefined);
        }, 2000);

        return () => clearInterval(timer);
    }, [uuid, active]);

    const providers = results?.providers ?? DEFAULT_PROVIDERS;
    const providerName = providers.find((p) => p.id === provider)?.name ?? provider;
    const totalPages = results ? Math.max(1, Math.ceil(results.total / results.per_page)) : 1;
    const recentlyFinished = !!install && !active && Math.floor(Date.now() / 1000) - install.updated_at < 3600;
    const showBanner = !!install && !showProgress && (active || (recentlyFinished && dismissed !== install.started_at));

    return (
        <ServerContentBlock title={'Modpacks'}>
            <h2 css={tw`text-2xl font-semibold text-white mb-3`}>Modpacks</h2>

            <div css={[strip, tw`p-4 mb-4 text-sm text-neutral-200`]}>
                Install a complete modpack server in one click. Everything on this server is replaced after a backup is taken
                automatically.
            </div>

            {showBanner && install && (
                <div css={[strip, tw`p-3 mb-4 flex items-center justify-between gap-3`]}>
                    <p css={tw`text-sm text-neutral-100`}>
                        {active ? (
                            <span>A modpack installation is in progress.</span>
                        ) : install.stage === 'done' ? (
                            <span>The modpack was installed.</span>
                        ) : (
                            <span>A modpack installation did not finish.</span>
                        )}
                    </p>
                    <div css={tw`flex gap-2`}>
                        <Button type={'button'} onClick={() => setShowProgress(true)}>
                            View details
                        </Button>
                        {!active && (
                            <Button.Text type={'button'} onClick={() => setDismissed(install.started_at)}>
                                Dismiss
                            </Button.Text>
                        )}
                    </div>
                </div>
            )}

            <form
                css={tw`flex gap-2 mb-4`}
                onSubmit={(e) => {
                    e.preventDefault();
                    setPage(1);
                    setQuery(typed.trim());
                }}
            >
                <select
                    value={provider}
                    onChange={(e) => {
                        setPage(1);
                        setProvider(e.target.value);
                    }}
                    aria-label={'Provider'}
                    css={tw`rounded-lg px-3 py-2 bg-black bg-opacity-30 border border-neutral-600 text-neutral-100`}
                >
                    {providers.map((p) => (
                        <option key={p.id} value={p.id} disabled={!p.enabled}>
                            {p.enabled ? p.name : `${p.name} (coming soon)`}
                        </option>
                    ))}
                </select>
                <input
                    value={typed}
                    onChange={(e) => setTyped(e.target.value)}
                    placeholder={'Search modpacks…'}
                    maxLength={100}
                    css={tw`flex-1 rounded-lg px-3 py-2 bg-black bg-opacity-30 border border-neutral-600 text-neutral-100`}
                />
                <Button type={'submit'}>Search</Button>
            </form>

            {error && <p css={tw`text-sm text-red-400 mb-3`}>{error}</p>}

            {loading ? (
                <div css={tw`flex justify-center py-10`}>
                    <Spinner size={'large'} />
                </div>
            ) : results && results.hits.length > 0 ? (
                <>
                    <div css={grid}>
                        {results.hits.map((pack) => (
                            <ModpackCard key={pack.id} pack={pack} onInstall={() => setPicked(pack)} disabled={active} />
                        ))}
                    </div>
                    {totalPages > 1 && (
                        <div css={tw`flex items-center justify-center gap-3 mt-4`}>
                            <Button type={'button'} onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page <= 1}>
                                Previous
                            </Button>
                            <span css={tw`text-sm text-neutral-300`}>
                                <span>Page</span> <span>{page}</span> / <span>{totalPages}</span>
                            </span>
                            <Button type={'button'} onClick={() => setPage((p) => p + 1)} disabled={page >= totalPages}>
                                Next
                            </Button>
                        </div>
                    )}
                </>
            ) : (
                !error && <p css={tw`text-sm text-neutral-300 text-center py-8`}>No modpacks found.</p>
            )}

            {picked && !showProgress && (
                <InstallModal
                    uuid={uuid}
                    provider={provider}
                    providerName={providerName}
                    pack={picked}
                    memoryMb={memoryMb}
                    onClose={() => setPicked(null)}
                    onStarted={(state) => {
                        setInstall(state);
                        setPicked(null);
                        setShowProgress(true);
                    }}
                />
            )}
            {showProgress && install && <ProgressModal uuid={uuid} state={install} onClose={() => setShowProgress(false)} />}
        </ServerContentBlock>
    );
};

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const enabled = ServerContext.useStoreState((state) => state.server.data!.lagplaceModpacksEnabled);
    const advanced = ServerContext.useStoreState((state) => state.server.data!.lagplaceAdvancedAccess);
    const rootAdmin = useStoreState((state) => state.user.data!.rootAdmin);

    if (!rootAdmin && !enabled) return <Notice>Modpacks are not available yet.</Notice>;
    if (!rootAdmin && !advanced) return <Notice>Modpacks need advanced access on your account.</Notice>;

    return <Content uuid={uuid} />;
};
