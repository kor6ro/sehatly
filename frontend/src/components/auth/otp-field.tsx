import type { RefObject } from 'react';
import { useFieldControl } from '@/components/form/field';
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
 * Lives outside the OTP page because the sign-in dialog verifies a code too, and two
 * implementations of "six boxes that are one field" would drift on the first accessibility
 * fix.
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
