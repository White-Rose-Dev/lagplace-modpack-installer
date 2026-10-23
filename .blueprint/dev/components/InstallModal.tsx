import React, { useEffect, useState } from 'react';
import tw from 'twin.macro';
import Spinner from '@/components/elements/Spinner';
import { Button } from '@/components/elements/button';
import ModalShell from './ModalShell';
import { errorMessage, getModpackVersions, InstallState, installModpack, ModpackHit, ModpackVersion } from './modpacks';

const row = tw`flex justify-between text-sm py-1`;

export default ({
    uuid,
    provider,
    providerName,
    pack,
    memoryMb,
    onClose,
    onStarted,
}: {
    uuid: string;
    provider: string;
    providerName: string;
    pack: ModpackHit;
    memoryMb: number;
    onClose: () => void;
    onStarted: (state: InstallState) => void;
}) => {
    const [versions, setVersions] = useState<ModpackVersion[] | null>(null);
    const [versionId, setVersionId] = useState('');
    const [understood, setUnderstood] = useState(false);
    const [eula, setEula] = useState(false);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        let alive = true;
        getModpackVersions(uuid, provider, pack.id)
            .then((list) => {
                if (!alive) return;
                setVersions(list);
                setVersionId(list[0]?.id ?? '');
            })
            .catch((e) => alive && (setError(errorMessage(e)), setVersions([])));

        return () => {
            alive = false;
        };
    }, [uuid, provider, pack.id]);

    const selected = versions?.find((v) => v.id === versionId);

    const start = () => {
        setSending(true);
        setError('');
        installModpack(uuid, provider, versionId)
            .then(onStarted)
            .catch((e) => {
                setError(errorMessage(e));
                setSending(false);
            });
    };

    return (
        <ModalShell onClose={onClose} closeOnOutside={!sending}>
            <h2 css={tw`text-xl font-semibold text-white mb-1`}>
                <span>Install</span> <span>{pack.title}</span>
            </h2>
            <p css={tw`text-xs text-neutral-400 mb-4`}>{'From ' + providerName}</p>

            {versions === null ? (
                <div css={tw`flex justify-center py-6`}>
                    <Spinner size={'large'} />
                </div>
            ) : versions.length === 0 ? (
                <p css={tw`text-sm text-neutral-300 mb-4`}>No versions of this modpack could be loaded.</p>
            ) : (
                <>
                    <p css={tw`text-xs font-semibold uppercase tracking-wider text-neutral-400 mb-1`}>Version</p>
                    <select
                        value={versionId}
                        onChange={(e) => setVersionId(e.target.value)}
                        disabled={sending}
                        css={tw`w-full mb-3 rounded-lg px-3 py-2 bg-black bg-opacity-30 border border-neutral-600 text-neutral-100`}
                    >
                        {versions.map((v) => (
                            <option key={v.id} value={v.id}>
                                {v.versionNumber || v.name}
                                {v.gameVersions[0] ? ` · ${v.gameVersions[0]}` : ''}
                                {v.loaders[0] ? ` · ${v.loaders[0]}` : ''}
                            </option>
                        ))}
                    </select>

                    {selected && (
                        <div css={tw`bg-black bg-opacity-20 rounded-lg px-4 py-2 mb-4`}>
                            <div css={row}>
                                <span css={tw`text-neutral-400`}>Minecraft</span>
                                <span css={tw`text-neutral-100`}>{selected.gameVersions.join(', ') || '—'}</span>
                            </div>
                            <div css={row}>
                                <span css={tw`text-neutral-400`}>Mod loader</span>
                                <span css={tw`text-neutral-100`}>{selected.loaders.join(', ') || '—'}</span>
                            </div>
                        </div>
                    )}

                    <div css={tw`mb-3 rounded-lg border border-red-700 bg-red-900 bg-opacity-30 p-3 text-sm text-red-200`}>
                        This will delete every file on this server and install the modpack. A backup is created first and kept
                        so you can restore it.
                    </div>

                    {memoryMb > 0 && memoryMb < 4096 && (
                        <p css={tw`mb-3 text-xs text-yellow-300`}>
                            <span>This server has</span> <span>{memoryMb}</span> <span>MB of memory. Most modpacks need 4 GB or more.</span>
                        </p>
                    )}

                    <label css={tw`flex items-start gap-2 mb-2 cursor-pointer`}>
                        <input
                            type={'checkbox'}
                            checked={understood}
                            onChange={(e) => setUnderstood(e.target.checked)}
                            disabled={sending}
                            style={{ marginTop: '0.2rem' }}
                        />
                        <span css={tw`text-sm text-neutral-200`}>I understand that all current files will be replaced.</span>
                    </label>
                    <label css={tw`flex items-start gap-2 mb-4 cursor-pointer`}>
                        <input
                            type={'checkbox'}
                            checked={eula}
                            onChange={(e) => setEula(e.target.checked)}
                            disabled={sending}
                            style={{ marginTop: '0.2rem' }}
                        />
                        <span css={tw`text-sm text-neutral-200`}>
                            <span>I accept the</span>{' '}
                            <a href={'https://aka.ms/MinecraftEULA'} target={'_blank'} rel={'noopener noreferrer'} css={tw`underline`}>
                                Minecraft EULA
                            </a>
                            <span>.</span>
                        </span>
                    </label>
                </>
            )}

            {error && <p css={tw`text-sm text-red-400 mb-3`}>{error}</p>}

            <div css={tw`flex justify-end gap-2`}>
                <Button type={'button'} onClick={onClose} disabled={sending}>
                    Cancel
                </Button>
                <Button type={'button'} onClick={start} disabled={sending || !understood || !eula || !selected}>
                    {sending ? 'Starting…' : 'Install modpack'}
                </Button>
            </div>
        </ModalShell>
    );
};
