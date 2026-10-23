import React, { useState } from 'react';
import tw from 'twin.macro';
import { Button } from '@/components/elements/button';
import ModalShell from './ModalShell';
import { cancelInstall, errorMessage, InstallState, isActive } from './modpacks';

const STAGES: Record<string, string> = {
    queued: 'Waiting to start',
    validating: 'Checking the modpack',
    backup: 'Creating a backup',
    loader: 'Installing the mod loader',
    pack: 'Downloading the modpack',
    overrides: 'Applying the configuration',
    mods: 'Downloading mods',
    verify: 'Checking the files',
    finalize: 'Finishing up',
};

export default ({
    uuid,
    state,
    onClose,
}: {
    uuid: string;
    state: InstallState;
    onClose: () => void;
}) => {
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const active = isActive(state);
    const waited = Math.floor(Date.now() / 1000) - state.started_at;

    const cancel = () => {
        setBusy(true);
        setError('');
        cancelInstall(uuid)
            .then(() => setConfirming(false))
            .catch((e) => setError(errorMessage(e)))
            .then(() => setBusy(false));
    };

    return (
        <ModalShell onClose={onClose} closeOnOutside={false}>
            <h2 css={tw`text-xl font-semibold text-white mb-1`}>
                {active ? <span>Installing modpack</span> : <span>Modpack installation</span>}
            </h2>
            <p css={tw`text-sm text-neutral-300 mb-4`} style={{ wordBreak: 'break-word' }}>
                {state.pack}
            </p>

            {active && (
                <>
                    <p css={tw`text-sm text-neutral-100 mb-2`}>{STAGES[state.stage] || state.stage}</p>
                    <div style={{ height: '0.5rem', borderRadius: '9999px', background: 'rgba(0,0,0,0.35)', overflow: 'hidden' }}>
                        <div
                            style={{
                                width: `${Math.max(2, state.percent)}%`,
                                height: '100%',
                                background: 'linear-gradient(90deg, #00ca96, #7fe9cd)',
                                transition: 'width 0.6s ease',
                            }}
                        />
                    </div>
                    <p css={tw`text-xs text-neutral-400 mt-2`} style={{ minHeight: '1.1rem', wordBreak: 'break-all' }}>
                        {state.stage === 'mods' && state.total > 0 ? (
                            <>
                                <span>{state.done}</span> / <span>{state.total}</span> · {state.detail}
                            </>
                        ) : (
                            state.detail
                        )}
                    </p>
                    {state.stage === 'queued' && waited > 60 && (
                        <p css={tw`text-xs text-yellow-300 mt-2`}>
                            Waiting for the installer to start. If this takes more than a minute, ask an admin to check the modpack
                            worker.
                        </p>
                    )}
                    <p css={tw`text-xs text-neutral-400 mt-3`}>
                        You can hide this window; the installation keeps running in the background.
                    </p>
                </>
            )}

            {state.stage === 'done' && (
                <p css={tw`text-sm text-green-300`}>The modpack is installed. Start your server from the Terminal tab.</p>
            )}
            {state.stage === 'failed' && (
                <>
                    <p css={tw`text-sm text-red-300`}>The installation failed.</p>
                    {state.error && <p css={tw`text-sm text-neutral-200 mt-2`}>{state.error}</p>}
                </>
            )}
            {state.stage === 'cancelled' && <p css={tw`text-sm text-yellow-300`}>The installation was cancelled.</p>}
            {!active && state.wiped && state.stage !== 'done' && (
                <p css={tw`text-sm text-neutral-200 mt-3`}>
                    The server&apos;s previous files were saved in a backup. Restore it from the Backups tab to get them back.
                </p>
            )}

            {error && <p css={tw`text-sm text-red-400 mt-3`}>{error}</p>}

            <div css={tw`flex justify-end gap-2 mt-5`}>
                {active && !confirming && (
                    <Button.Danger type={'button'} onClick={() => setConfirming(true)}>
                        Cancel installation
                    </Button.Danger>
                )}
                {active && confirming && (
                    <>
                        <span css={tw`text-sm text-neutral-200 self-center mr-1`}>Stop the installation?</span>
                        <Button type={'button'} onClick={() => setConfirming(false)} disabled={busy}>
                            No
                        </Button>
                        <Button.Danger type={'button'} onClick={cancel} disabled={busy}>
                            Yes, stop
                        </Button.Danger>
                    </>
                )}
                <Button type={'button'} onClick={onClose}>
                    {active ? 'Hide' : 'Close'}
                </Button>
            </div>
        </ModalShell>
    );
};
