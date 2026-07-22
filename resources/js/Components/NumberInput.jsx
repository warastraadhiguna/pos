import { useEffect, useState } from 'react';
import { formatDecimalID, parseTypedDecimalID } from '@/utils/decimalFormat';

/**
 * Input angka gaya Indonesia (titik ribuan, koma desimal) — mis. mengetik
 * "80020" menampilkan "80.020" sehingga salah ketik jumlah langsung
 * terlihat. `onChange` menerima string desimal biasa ("80020" / "80020.5")
 * yang siap dikirim ke backend, bukan teks yang sedang ditampilkan.
 */
export default function NumberInput({
    id,
    className = '',
    value,
    onChange,
    maxDecimals = 2,
    placeholder,
    required,
    autoFocus,
}) {
    const [text, setText] = useState(() => formatDecimalID(value, maxDecimals));
    const [focused, setFocused] = useState(false);

    useEffect(() => {
        if (!focused) {
            setText(formatDecimalID(value, maxDecimals));
        }
    }, [value, focused, maxDecimals]);

    const handleChange = (e) => {
        const { display, plain } = parseTypedDecimalID(e.target.value, maxDecimals);
        setText(display);
        onChange(plain);
    };

    return (
        <input
            id={id}
            type="text"
            inputMode="decimal"
            className={
                'rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary ' +
                className
            }
            value={text}
            placeholder={placeholder}
            required={required}
            autoFocus={autoFocus}
            onFocus={() => setFocused(true)}
            onBlur={() => setFocused(false)}
            onChange={handleChange}
        />
    );
}
