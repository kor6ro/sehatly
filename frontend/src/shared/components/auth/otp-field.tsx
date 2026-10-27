import type { RefObject } from 'react';
import { useFieldControl } from '@/shared/components/form/field';
import { KODE_OTP_PANJANG } from '@/lib/otp';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';

/**
 * The six boxes, wired to their `Field`.
 *
 * `InputOTP` renders an `<input>` with `inputMode="numeric"` and the `data-input-otp`
 * attribute, so the `Field` plumbing is applied to it by hand - `useFieldControl` is
 * exported for exactly this, and the kit's `InputOTP` cannot be modified (relocated
 * registry code). Slots are 44 px and 8 px apart so each one is a full touch target; the
 * whole row is one logical field with `autocomplete="one-time-code"`.
 *
 * It is its own module rather than markup inside the dialog that renders it, because
 * "six boxes that are one field" is a control and not a screen: it carries the
 * `autocomplete="one-time-code"` contract and the focus behaviour, and keeping it apart
 * is what stops a future second verifier from drifting on the first accessibility fix.
 */
export function OtpInput({
    value,
    onChange,
    disabled,
    inputRef,
}: {
    value: string;
    onChange: (value: string) => void;
    disabled: boolean;
    inputRef: RefObject<HTMLInputElement | null>;
}) {
    const control = useFieldControl();

    return (
        <InputOTP
            id={control.id}
            ref={inputRef}
            maxLength={KODE_OTP_PANJANG}
            value={value}
            onChange={onChange}
            disabled={disabled}
            autoComplete="one-time-code"
            inputMode="numeric"
            aria-invalid={control.invalid || undefined}
            aria-describedby={control.describedBy}
            containerClassName="justify-start"
        >
            <InputOTPGroup className="gap-2 tabular-nums">
                {Array.from({ length: KODE_OTP_PANJANG }, (_unused, index) => (
                    <InputOTPSlot
                        key={index}
                        index={index}
                        className="h-11 w-11 rounded-md border text-base"
                    />
                ))}
            </InputOTPGroup>
        </InputOTP>
    );
}
