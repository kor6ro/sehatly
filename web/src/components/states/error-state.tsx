import { AlertCircle, RefreshCw, ShieldAlert, SearchX } from 'lucide-react';
import { ApiError } from '@/lib/http';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

/**
 * The error state, and the one place the status code becomes words.
 *
 * ## Why 403 and 404 must not share a sentence
 *
 * The API uses the two deliberately and non-interchangeably:
 *
 * | status | what it means here | who it is about |
 * | --- | --- | --- |
 * | 403 | the account owns no `pasien` row, so it may not act on a patient record at all | the caller |
 * | 404 | the row is missing, or belongs to somebody else | the data |
 *
 * A 403 is worth "your account is not a patient account" and nothing more - it discloses
 * nothing about anybody else's record. A 404 is "that row is not there, and whether it
 * ever existed is not yours to know", because `PasienController` answers 404 rather than
 * 403 for another patient's row precisely so existence is not leaked across tenants.
 *
 * Collapsing them would tell a patient that their own family member does not exist, and
 * would tell an operator with a misconfigured account that their data is gone. So the
 * titles differ, and the retry button is omitted for both, because neither is fixed by
 * asking again.
 */

/**
 * The wording for a status, split from the component so a page can reuse the same
 * distinction inside a form (a failed write) as at the top of a page (a failed read).
 */
function describe(error: unknown): {
    title: string;
    detail: string;
    retryable: boolean;
} {
    if (error instanceof ApiError) {
        if (error.isNotFound) {
            return {
                title: 'Data tidak ditemukan',
                detail: error.message,
                retryable: false,
            };
        }

        if (error.isForbidden) {
            return {
                title: 'Akun ini tidak berhak',
                detail: error.message,
                retryable: false,
            };
        }

        if (error.isUnauthorized) {
            return {
                title: 'Sesi berakhir',
                detail: error.message,
                retryable: false,
            };
        }

        if (error.isValidation) {
            return {
                title: 'Data tidak valid',
                detail: error.message,
                retryable: false,
            };
        }

        return {
            title: 'Gagal memuat data',
            detail: error.message,
            retryable: true,
        };
    }

    if (error instanceof Error) {
        return {
            title: 'Gagal memuat data',
            detail: error.message,
            retryable: true,
        };
    }

    return {
        title: 'Gagal memuat data',
        detail: 'Terjadi kesalahan yang tidak diketahui.',
        retryable: true,
    };
}

export function ErrorState({
    error,
    onRetry,
    title,
    className,
}: {
    error: unknown;
    onRetry?: () => void;
    /**
     * Overrides the status-derived heading. A panel that failed can say what failed
     * ("Gagal memuat antrean.") without inventing a fourth status message; the detail
     * and the retryability still come from {@link describe}.
     */
    title?: string;
    className?: string;
}) {
    const { title: judul, detail, retryable } = describe(error);

    return (
        <Alert variant="destructive" data-slot="error-state" className={className}>
            <AlertCircle />

            <AlertTitle>{title ?? judul}</AlertTitle>

            <AlertDescription>
                <p>{detail}</p>

                {onRetry !== undefined && retryable ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onRetry}
                        className="mt-1 min-h-11"
                    >
                        <RefreshCw />
                        Coba lagi
                    </Button>
                ) : null}
            </AlertDescription>
        </Alert>
    );
}

/**
 * The not-found state as its own component, because it is a **destination** rather than a
 * failure: a doctor who is not in the directory is not an error, and a user who followed a
 * stale link deserves a route back rather than a red box.
 *
 * `DokterController` answers one 404 for six different situations - never existed, not
 * verified, inactive, not on telemedicine, STR expired, account soft-deleted - and the
 * client is forbidden from distinguishing them, so the copy says only what it is allowed
 * to say: this profile is not available in the public directory.
 */
export function NotFoundState({
    title = 'Profil tidak tersedia',
    detail = 'Profil ini tidak ada atau tidak lagi dipublikasikan di direktori.',
    action,
    className,
}: {
    title?: string;
    detail?: string;
    action?: React.ReactNode;
    className?: string;
}) {
    return (
        <div
            role="status"
            data-slot="not-found-state"
            className={className}
        >
            <Alert variant="destructive">
                <SearchX />

                <AlertTitle>{title}</AlertTitle>

                <AlertDescription>
                    <p>{detail}</p>

                    {action}
                </AlertDescription>
            </Alert>
        </div>
    );
}

/**
 * A 403 in a form context, where the whole screen is not a failure but the caller's
 * account type is wrong. Kept separate from {@link ErrorState} so a page can say it
 * without also offering a retry that cannot help.
 *
 * `title` is overridable because F02's mandatory-consent gate reuses this presentation
 * for a second kind of refusal that is not about a role: a patient who has not yet
 * approved the three required consents is entitled to act, and telling them their account
 * "tidak berhak" would be false. The retry button stays absent in both cases because
 * asking again changes nothing - the action is the link the caller passes in.
 */
export function ForbiddenState({
    title = 'Akun ini tidak berhak',
    detail = 'Akun ini tidak memiliki data pasien, sehingga halaman ini tidak dapat dibuka.',
    action,
    className,
}: {
    title?: string;
    detail?: string;
    action?: React.ReactNode;
    className?: string;
}) {
    return (
        <Alert variant="destructive" data-slot="forbidden-state" className={className}>
            <ShieldAlert />

            <AlertTitle>{title}</AlertTitle>

            <AlertDescription>
                <p>{detail}</p>

                {action}
            </AlertDescription>
        </Alert>
    );
}
