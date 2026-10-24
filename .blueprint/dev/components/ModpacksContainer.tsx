import React, { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { field, Filter, grid, strip } from '@/components/lagplace/ResourceCard';
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
    getInstalledModpack,
    getInstallStatus,
    InstalledModpack,
    InstallState,
    isActive,
    ModpackHit,
    ModpackSearch,
    ProviderInfo,
    searchModpacks,
} from './modpacks';

const DEFAULT_PROVIDERS: ProviderInfo[] = [
    { id: 'modrinth', name: 'Modrinth', enabled: true },
    { id: 'curseforge', name: 'CurseForge', enabled: false },
    { id: 'ftb', name: 'Feed The Beast', enabled: true },
    { id: 'atlauncher', name: 'ATLauncher', enabled: false },
    { id: 'technic', name: 'Technic', enabled: false },
];

const LOADER_NAMES: Record<string, string> = { forge: 'Forge', neoforge: 'NeoForge', fabric: 'Fabric', quilt: 'Quilt' };

const Notice = ({ children }: { children: React.ReactNode }) => (
    <ServerContentBlock title={'Modpacks'}>
        <div css={[strip, tw`p-4 text-sm text-neutral-200`]}>{children}</div>
    </ServerContentBlock>
);

const Content = ({ uuid }: { uuid: string }) => {
    const [typed, setTyped] = useState('');
    const [query, setQuery] = useState('');
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(20);
    const [recent, setRecent] = useState<InstalledModpack | null>(null);
    const [provider, setProvider] = useState('modrinth');
    const [results, setResults] = useState<ModpackSearch | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [picked, setPicked] = useState<ModpackHit | null>(null);
    const [install, setInstall] = useState<InstallState | null>(null);
    const [showProgress, setShowProgress] = useState(false);
    const [dismissed, setDismissed] = useState(0);
    const memoryMb = ServerContext.useStoreState((state) => state.server.data!.limits.memory);
    const serverStatus = ServerContext.useStoreState((state) => state.status.value);

    useEffect(() => {
        let alive = true;
        setLoading(true);
        setError('');
        searchModpacks(uuid, provider, query, page, perPage)
            .then((r) => alive && setResults(r))
            .catch((e) => alive && setError(errorMessage(e)))
            .then(() => alive && setLoading(false));

        return () => {
            alive = false;
        };
    }, [uuid, provider, query, page, perPage]);

    const justFinished = install?.stage === 'done';
    useEffect(() => {
        let alive = true;
        getInstalledModpack(uuid)
            .then((r) => alive && setRecent(r))
            .catch(() => undefined);

        return () => {
            alive = false;
        };
    }, [uuid, justFinished]);

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
    const showBanner =
        !!install &&
        !showProgress &&
        (active || (recentlyFinished && install.stage !== 'done' && dismissed !== install.started_at));

    return (
        <ServerContentBlock title={'Modpacks'}>
            <h2 css={tw`text-2xl font-semibold text-white mb-3`}>Modpacks</h2>

            {recent && !active && (
                <div css={[strip, tw`p-4 mb-4`]}>
                    <p css={tw`text-xs uppercase tracking-wide text-neutral-400`}>Most recently installed modpack</p>
                    <div css={tw`flex items-center flex-wrap gap-2 mt-1`}>
                        <p css={tw`text-lg font-semibold text-white`} style={{ wordBreak: 'break-word' }}>
                            {recent.pack}
                        </p>
                        {[recent.loader ? LOADER_NAMES[recent.loader] : null, recent.minecraft ? `Minecraft ${recent.minecraft}` : null]
                            .filter((chip): chip is string => !!chip)
                            .map((chip) => (
                                <span
                                    key={chip}
                                    css={tw`text-xs rounded px-2 py-px`}
                                    style={{ background: 'rgba(0,202,150,0.15)', color: '#7fe9cd' }}
                                >
                                    {chip}
                                </span>
                            ))}
                    </div>
                    <p css={tw`text-sm text-green-300 mt-1`}>
                        {serverStatus === 'offline'
                            ? 'The modpack is installed. Start your server from the Terminal tab.'
                            : 'The modpack is installed.'}
                    </p>
                    {recent.installed_at && (
                        <p css={tw`text-xs text-neutral-400 mt-1`}>
                            <span>Installed</span> <span>{new Date(recent.installed_at).toLocaleString()}</span>
                        </p>
                    )}
                </div>
            )}

            <div css={[strip, tw`p-4 mb-4 text-sm text-neutral-200`]}>
                Install a complete modpack server in one click. Everything on this server is replaced after a backup is taken
                automatically.
            </div>

            {showBanner && install && (
                <div css={[strip, tw`p-3 mb-4 flex items-center justify-between gap-3`]}>
                    <p css={tw`text-sm text-neutral-100`}>
                        {active ? (
                            <span>A modpack installation is in progress.</span>
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
                css={tw`flex flex-wrap items-end gap-3 mb-4`}
                onSubmit={(e) => {
                    e.preventDefault();
                    setPage(1);
                    setQuery(typed.trim());
                }}
            >
                <Filter label={'Provider'} htmlFor={'modpack_provider'}>
                    <select
                        id={'modpack_provider'}
                        value={provider}
                        onChange={(e) => {
                            setPage(1);
                            setProvider(e.target.value);
                        }}
                        css={field}
                    >
                        {providers.map((p) => (
                            <option key={p.id} value={p.id} disabled={!p.enabled}>
                                {p.enabled ? p.name : `${p.name} (coming soon)`}
                            </option>
                        ))}
                    </select>
                </Filter>
                <Filter label={'Page size'} htmlFor={'modpack_page_size'}>
                    <select
                        id={'modpack_page_size'}
                        value={perPage}
                        onChange={(e) => {
                            setPage(1);
                            setPerPage(Number(e.target.value));
                        }}
                        css={field}
                    >
                        <option value={10}>10</option>
                        <option value={20}>20</option>
                        <option value={50}>50</option>
                    </select>
                </Filter>
                <div css={tw`w-full md:w-auto md:flex-1`}>
                    <Filter label={'Search query'} htmlFor={'modpack_search'}>
                        <input
                            id={'modpack_search'}
                            value={typed}
                            onChange={(e) => setTyped(e.target.value)}
                            placeholder={'Search modpacks…'}
                            maxLength={100}
                            css={[field, tw`w-full`]}
                        />
                    </Filter>
                </div>
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
