import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * The visitor's position, read from the browser and remembered for the session.
 *
 * This is the client half of `?sort=terdekat`. The server measures the distance; nothing
 * here computes one. All this does is answer "where is the person asking?" with a
 * position, a refusal, or an honest "could not find out" - and the answer decides whether
 * `lat`/`lng` are sent at all.
 *
 * ## It is asked HERE, when an answer appears, and not on the marketing page
 *
 * A permission prompt over a page of promos is a question with no visible reason. The
 * visitor only wants a distance once they have asked for a specialisation, so the first
 * call happens when the answering body mounts. A returning visitor inside the same
 * session is never asked again: the coordinates are in `sessionStorage`.
 *
 * ## A refusal is remembered; a failure is not
 *
 * The two are different facts and are kept apart on purpose.
 *
 * | state | means | remembered? |
 * | --- | --- | --- |
 * | `ditolak` | the visitor pressed "Block" | yes - re-prompting after a refusal is how a site gets itself permanently blocked |
 * | `gagal` | no fix in time, or no signal | no - the next visit may well work |
 * | `tidak-sedia` | no `navigator.geolocation` at all, or an origin the browser will not offer it on | no, and it cannot be retried |
 *
 * All three render the same list with no distance and the same honest line; only the
 * wording of the line and whether a retry button makes sense differ. **None of them is
 * ever answered with a made-up number.** A distance of `0`, or a distance to the middle of
 * the region the browser guessed, would be a lie about the one thing this feature exists
 * to tell the truth about.
 *
 * ## It blocks the list - briefly, and only to avoid a re-sort the reader can see
 *
 * The caller shows skeletons while `meminta`, so the order a reader starts reading is the
 * order they keep. The alternative was to fire the request immediately without a position,
 * let the list appear in the default order, and re-sort it a second or two later when the
 * fix arrived - which is faster to first paint and puts a list that rearranges itself under
 * the eye of somebody who was already reading it.
 *
 * The wait is bounded on three sides: the browser's own `timeout`, a watcher below in case
 * that promise is not kept, and a returning visitor who skips it entirely because their
 * last position is still in `sessionStorage`.
 */

export type StatusPosisi =
    /** Asking, or the browser is working it out. Short, and the caller shows skeletons. */
    | 'meminta'
    /** Granted, with coordinates in hand. This is the only state that sends `lat`/`lng`. */
    | 'aktif'
    /** The visitor refused. Remembered for the session so they are not asked again. */
    | 'ditolak'
    /** No fix in time, or the position is unavailable. Worth offering a retry. */
    | 'gagal'
    /** No `geolocation` at all - an old browser, or an origin the browser withholds it on. */
    | 'tidak-sedia';

export type PosisiPasien = {
    status: StatusPosisi;
    lat: number | null;
    lng: number | null;
    /** Ask again - clearing a remembered refusal first, which is the only way back from it. */
    minta: () => void;
};

/**
 * One session-length record of what happened last time.
 *
 * `sessionStorage` and not `localStorage`: a refusal is a decision about THIS visit, and
 * a patient who comes back tomorrow deserves to be asked once rather than carry
 * yesterday's "no" forever with nothing on screen saying so.
 */
const JEJAK_STATUS = 'sehatly.posisi.status';
const JEJAK_KOORDINAT = 'sehatly.posisi.koordinat';

/**
 * How long the browser may take before this gives up and shows the unsorted list.
 *
 * Five seconds is long enough for a warm fix (`maximumAge` below lets the browser reuse
 * one from the last five minutes, which is instant) and short enough that a visitor who
 * ignores the prompt is not left staring at skeletons.
 *
 * This is what `getCurrentPosition` is ASKED for, not what it is trusted to do - see the
 * watcher in the hook below, which is the deadline this one is not allowed to miss.
 */
const BATAS_WAKTU_MS = 5_000;

function bacaJejakKoordinat(): { lat: number; lng: number } | null {
    try {
        const tersimpan = window.sessionStorage.getItem(JEJAK_KOORDINAT);

        if (tersimpan === null) {
            return null;
        }

        const [lat, lng] = tersimpan.split(',').map(Number);

        // A stored value that will not parse is treated as absent rather than as zero:
        // `0,0` is a real point in the Gulf of Guinea and no patient is standing on it.
        return Number.isFinite(lat) && Number.isFinite(lng) ? { lat, lng } : null;
    } catch {
        return null;
    }
}

function simpanJejak(
    status: StatusPosisi | null,
    koordinat: { lat: number; lng: number } | null,
): void {
    try {
        if (status === null) {
            // Forgetting both keys at once. A visitor who has never been asked and a
            // visitor who failed to be asked arrive at the same next visit, which is the
            // only thing that matters here: ask again.
            window.sessionStorage.removeItem(JEJAK_STATUS);
            window.sessionStorage.removeItem(JEJAK_KOORDINAT);
            return;
        }

        window.sessionStorage.setItem(JEJAK_STATUS, status);

        if (koordinat === null) {
            window.sessionStorage.removeItem(JEJAK_KOORDINAT);
        } else {
            window.sessionStorage.setItem(
                JEJAK_KOORDINAT,
                `${koordinat.lat},${koordinat.lng}`,
            );
        }
    } catch {
        // Storage refused (private mode, disabled cookies) costs only the memory: the
        // visitor is asked once more next time, which is the behaviour without this hook.
    }
}

function bacaJejakStatus(): StatusPosisi | null {
    try {
        const tersimpan = window.sessionStorage.getItem(JEJAK_STATUS);

        return tersimpan === 'aktif' || tersimpan === 'ditolak' ? tersimpan : null;
    } catch {
        return null;
    }
}

/**
 * The browser's own error code, and what it is honest to do about it.
 *
 * `1` is `PermissionDenied` and is the only one worth remembering: the other two are
 * `PositionUnavailable` and `TIMEOUT`, both of which say "not right now" rather than
 * "not ever". Mapping everything to a refusal would stop the visitor being asked again
 * after a lift ride through a tunnel.
 */
function statusDariGalat(kode: number): StatusPosisi {
    return kode === 1 ? 'ditolak' : 'gagal';
}

function statusAwal(): StatusPosisi {
    if (typeof navigator === 'undefined' || !('geolocation' in navigator)) {
        return 'tidak-sedia';
    }

    const jejak = bacaJejakStatus();

    if (jejak === 'ditolak') {
        return 'ditolak';
    }

    if (jejak === 'aktif' && bacaJejakKoordinat() !== null) {
        return 'aktif';
    }

    return 'meminta';
}

export function usePosisiPasien(): PosisiPasien {
    const [status, setStatus] = useState<StatusPosisi>(statusAwal);
    const [koordinat, setKoordinat] = useState<{ lat: number; lng: number } | null>(
        bacaJejakKoordinat,
    );

    /**
     * The watcher that guarantees this resolves.
     *
     * `getCurrentPosition` is given its own `timeout` above, and every browser that offers
     * the API honours it - but "honours" is a promise about a moving target, and the cost
     * of being wrong here is total: the answering body of the landing page shows skeletons
     * for as long as this stays `meminta`, so a browser that never calls back would leave
     * a patient staring at placeholders forever instead of at a doctor.
     *
     * So the call is given a deadline of its own, a second past the browser's. It is not a
     * race the faster one loses: both outcomes move the state OFF `meminta`, and the first
     * to do so wins while the other becomes a no-op.
     */
    const pengawas = useRef<number | null>(null);

    const minta = useCallback(() => {
        if (typeof navigator === 'undefined' || !('geolocation' in navigator)) {
            setStatus('tidak-sedia');
            return;
        }

        if (pengawas.current !== null) {
            window.clearTimeout(pengawas.current);
        }

        pengawas.current = window.setTimeout(() => {
            setStatus((sekarang) => (sekarang === 'meminta' ? 'gagal' : sekarang));
            setKoordinat(null);
        }, BATAS_WAKTU_MS + 1_000);

        setStatus('meminta');

        navigator.geolocation.getCurrentPosition(
            (posisi) => {
                window.clearTimeout(pengawas.current ?? undefined);
                pengawas.current = null;

                const berikut = {
                    lat: posisi.coords.latitude,
                    lng: posisi.coords.longitude,
                };

                simpanJejak('aktif', berikut);
                setKoordinat(berikut);
                setStatus('aktif');
            },
            (galat) => {
                window.clearTimeout(pengawas.current ?? undefined);
                pengawas.current = null;

                const berikut = statusDariGalat(galat.code);

                // A refusal is remembered so the prompt does not return on every
                // navigation. A failure is forgotten, because it is usually the lift,
                // the tunnel or the first second after a page load.
                simpanJejak(berikut === 'ditolak' ? 'ditolak' : null, null);
                setKoordinat(null);
                setStatus(berikut);
            },
            {
                // `enableHighAccuracy` off on purpose: this sorts a list by kilometres,
                // so a GPS fix is a battery cost for no visible difference. The browser
                // falls back to network and cell location, which is plenty.
                enableHighAccuracy: false,
                timeout: BATAS_WAKTU_MS,
                // A fix from the last five minutes is reused without asking the radio at
                // all, which is what makes a returning visitor's first paint instant.
                maximumAge: 300_000,
            },
        );
    }, []);

    // Ask on mount when there is nothing remembered yet. A remembered refusal or a
    // remembered position both skip the prompt entirely.
    useEffect(() => {
        if (status === 'meminta' && koordinat === null) {
            minta();
        }

        // `status` and `minta` are deliberately not dependencies: the first changes as a
        // RESULT of this call, and the second is stable, so re-running on either would
        // ask the browser twice for one visit.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Clear the watcher when the component goes away, so a position that arrives after
    // the answer has unmounted cannot write state nobody is reading.
    useEffect(
        () => () => {
            if (pengawas.current !== null) {
                window.clearTimeout(pengawas.current);
            }
        },
        [],
    );

    return { status, lat: koordinat?.lat ?? null, lng: koordinat?.lng ?? null, minta };
}
