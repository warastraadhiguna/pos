import { forwardRef } from 'react';

export default forwardRef(function SelectInput(
    { className = '', children, ...props },
    ref,
) {
    return (
        <select
            {...props}
            className={
                'rounded-md border-gray-300 shadow-sm focus:border-primary focus:ring-primary ' +
                className
            }
            ref={ref}
        >
            {children}
        </select>
    );
});
