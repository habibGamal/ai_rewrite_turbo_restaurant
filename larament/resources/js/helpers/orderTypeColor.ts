const colors: Record<string, string> = {
    success: 'green',
    info: 'blue',
    danger: 'red',
    warning: 'gold',
    primary: 'geekblue',
    gray: 'default',
};

/**
 * Maps a Filament color name (from order type rows) to an Ant Design Tag color.
 */
export const orderTypeColor = (color: string | undefined): string =>
    (color && colors[color]) || 'default';
