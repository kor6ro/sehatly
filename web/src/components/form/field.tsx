import { createContext, useContext, useId, type ComponentProps, type ReactNode } from 'react';
import { AlertCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

/**
 * Form plumbing: labels, per-field errors, and the summary above a form.
 *
 * ## Why the 422 detail is rendered per field and never as one toast
 *
 * The failure envelope carries `errors: { field: [messages] }`, and two things put more
 * than one message on a single key: `AuthRequest::requireAtLeastOneIdentifier()` writes
 * the same "Isi no_telepon atau email." onto *both* identifier keys, and
 * `UpdatePasienProfileRequest::after()` can add a second coherence message to a wilayah key
 * that already failed an `exists` rule. So this component takes a **list** per field and
 * renders all of it, and a toast - which has room for one line - is the wrong instrument
 * for a structured, per-field, possibly multi-message answer.
 *
 * The summary exists *in addition*, not instead: a form taller than the viewport needs a way
 * to see that something above it failed.
 */

/**
 * What a `Field` hands to the control it wraps.
 *
 * A context rather than `cloneElement` because a `Field` wraps a `shadcn` `Select`, the OTP
 * input and a plain `Input` alike, and cloning props onto those fights React and silently
 * drops props it does not recognise. It is also the only mechanism that reaches
 * `components/ui/*` without editing it, which matters: that directory is relocated registry
 * code and this todo must not touch it.
 */
type FieldControlValue = {
    id: string;
    invalid: boolean;
    describedBy: string | undefined;
};

const FieldControlContext = createContext<FieldControlValue>({
    id: '',
    invalid: false,
    describedBy: undefined,
});

/**
 * The wiring a `Field` has applied, for a control the kit does not own.
 *
 * `Select` and `InputOTP` both need `id`, `aria-invalid` and `aria-describedby` set by
 * hand, and forgetting one of the three produces a control that looks wrong to a sighted
 * user and says nothing to a screen reader.
 */
export function useFieldControl(): FieldControlValue {
    return useContext(FieldControlContext);
}

export function Field({
    label,
    hideLabel,
    errors = [],
    hint,
    required,
    className,
    slot,
    children,
}: {
    label: string;
    /**
     * Keeps the label in the accessibility tree and drops it from the page.
     *
     * For a control whose purpose is already stated by a heading above it - the sign-in
     * dialog says "Masukkan Nomor Ponsel" right over the input - a second visible line
     * is redundancy the reader has to skip, and removing it outright would leave the
     * control unnamed for anything that does not see headings. `sr-only` is the
     * difference: the name stays, the line does not.
     */
    hideLabel?: boolean;
    errors?: string[];
    hint?: string;
    required?: boolean;
    className?: string;
    /**
     * Replaces the emitted `data-slot="field"` so a caller can address ONE field in a form
     * that holds several.
     *
     * `FieldSelect` renders its trigger through `useId()`, so the generated `id` differs on
     * every mount and is not a stable selector; and neither `Field` nor `FieldSelect` spreads
     * unknown props, so a `data-slot` passed to either is silently dropped rather than
     * rejected. This is the one place a form field gets a stable test hook, which is what
     * the checkout and payment-method pickers need to assert on.
     */
    slot?: string;
    children: ReactNode;
}) {
    const id = useId();
    const errorId = `${id}-error`;
    const hintId = `${id}-hint`;
    const hasError = errors.length > 0;

    const describedBy =
        [hasError ? errorId : null, hint === undefined ? null : hintId]
            .filter((value): value is string => value !== null)
            .join(' ') || undefined;

    return (
        <div
            data-slot={slot ?? 'field'}
            className={cn('flex flex-col gap-1.5', className)}
        >
            <Label
                htmlFor={id}
                className={hideLabel === true ? 'sr-only' : undefined}
            >
                {label}

                {required === true ? (
                    <span aria-hidden className="text-destructive ml-0.5">
                        *
                    </span>
                ) : null}
            </Label>

            <FieldControlContext.Provider value={{ id, invalid: hasError, describedBy }}>
                {children}
            </FieldControlContext.Provider>

            {hint === undefined ? null : (
                <p id={hintId} className="text-muted-foreground text-xs">
                    {hint}
                </p>
            )}

            {hasError ? (
                <ul id={errorId} className="text-destructive flex flex-col gap-0.5 text-xs">
                    {errors.map((message) => (
                        <li key={message} className="flex items-start gap-1.5">
                            <AlertCircle aria-hidden className="mt-0.5 size-3 shrink-0" />

                            {message}
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}

/**
 * An `Input` already wired to its `Field`.
 *
 * `aria-invalid` and `aria-describedby` are applied here rather than at each call site
 * because `Input` already styles itself off `aria-invalid` (see `components/ui/input.tsx`),
 * and a control with a red ring that tells a screen reader nothing is a bug the styling
 * alone cannot fix.
 */
export function FieldInput({ className, ...props }: ComponentProps<typeof Input>) {
    const control = useFieldControl();

    return (
        <Input
            id={control.id}
            aria-invalid={control.invalid || undefined}
            aria-describedby={control.describedBy}
            className={className}
            {...props}
        />
    );
}

/**
 * A `Textarea` already wired to its `Field`.
 *
 * Added by Module 2, which is the first todo whose forms need a multi-line input - and
 * the only honest way it exists, because `Field`'s `Label` points at the generated `id`
 * and a bare `<Textarea>` spread with `register()` carries no `id` at all. A `Keluhan`
 * control no screen reader can reach is the defect this prevents.
 *
 * Forwarded refs are what make `register()` work on it: `useForm` attaches its `ref` to
 * the element, so this forwards everything `Textarea` forwards.
 */
export function FieldTextarea({
    className,
    rows,
    ...props
}: ComponentProps<typeof Textarea>) {
    const control = useFieldControl();

    return (
        <Textarea
            id={control.id}
            aria-invalid={control.invalid || undefined}
            aria-describedby={control.describedBy}
            className={className}
            rows={rows}
            {...props}
        />
    );
}
/**
 * A `Select` already wired to its `Field`.
 *
 * Radix's `SelectTrigger` renders a `<button>`, not an `<input>`, so the `id` and
 * `aria-describedby` a `Field` generates have to be applied by hand - which is exactly the
 * wiring a caller would otherwise forget. `aria-invalid` additionally drives the
 * destructive ring `SelectTrigger` already styles itself off.
 */
export function FieldSelect({
    value,
    onValueChange,
    placeholder,
    children,
    className,
    disabled,
}: {
    value: string;
    onValueChange: (value: string) => void;
    placeholder?: string;
    children: ReactNode;
    className?: string;
    disabled?: boolean;
}) {
    const control = useFieldControl();

    return (
        <Select value={value} onValueChange={onValueChange} disabled={disabled}>
            <SelectTrigger
                id={control.id}
                aria-invalid={control.invalid || undefined}
                aria-describedby={control.describedBy}
                className={className}
            >
                {/**
                 * FIXED: this rendered the raw `placeholder` string INSTEAD of
                 * `SelectValue`, so a `FieldSelect` could never display the value the user
                 * had chosen - the trigger kept saying "Pilih..." after a successful
                 * selection, and the surrounding form looked broken while the underlying
                 * state was correct.
                 *
                 * `SelectValue` takes `placeholder` as a PROP precisely for this: it shows
                 * the selected item's own text, and falls back to the placeholder only while
                 * nothing is selected. The three pre-existing call sites
                 * (`doctor-directory-page.tsx` twice, `register-page.tsx` once) all pass a
                 * placeholder, so all three were affected.
                 *
                 * Caught by driving the real UI, not by the type checker: the bug is a
                 * rendering mistake, not a type error, and the state behind it was correct
                 * the whole time.
                 */}
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>

            <SelectContent>{children}</SelectContent>
        </Select>
    );
}

/**
 * The failure shape this module needs, matched structurally.
 *
 * A structural check rather than `error instanceof ApiError` keeps the form layer free of
 * the HTTP module, which would otherwise pull the transport into every screen that renders
 * a label. Both keys are required before anything is rendered, so an unrelated thrown value
 * cannot be mistaken for a 422.
 */
function asValidationFailure(
    error: unknown,
): { message: string; fields: string[] } | null {
    if (typeof error !== 'object' || error === null) {
        return null;
    }

    if (!('message' in error) || !('errors' in error)) {
        return null;
    }

    const candidate = error as { message: unknown; errors: unknown };

    if (typeof candidate.errors !== 'object' || candidate.errors === null) {
        return null;
    }

    const fields = Object.keys(candidate.errors as Record<string, unknown>);

    if (fields.length === 0) {
        return null;
    }

    return {
        message:
            typeof candidate.message === 'string'
                ? candidate.message
                : 'Data yang dikirim tidak valid.',
        fields,
    };
}

/**
 * The summary above a form: how many fields the server rejected.
 *
 * `role="alert"` because a submission that failed has to be *announced*, not merely
 * displayed. The per-field list below it is what names the fields; this line exists so a
 * long form does not look unchanged after a rejected submit.
 */
export function FormErrorSummary({ error, className }: { error: unknown; className?: string }) {
    const failure = asValidationFailure(error);

    if (failure === null) {
        return null;
    }

    return (
        <Alert variant="destructive" role="alert" className={className}>
            <AlertCircle />

            <AlertTitle>{failure.fields.length} field perlu diperbaiki</AlertTitle>

            <AlertDescription>
                <p>{failure.message}</p>
            </AlertDescription>
        </Alert>
    );
}
