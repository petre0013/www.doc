export const AVATAR_COLORS = {
    sage:   { bg: '#DAE6D4', text: '#4B6840', label: 'Sage'   },
    sky:    { bg: '#D4E6F2', text: '#2E5F7E', label: 'Sky'    },
    amber:  { bg: '#F5E4C4', text: '#7A5520', label: 'Amber'  },
    rose:   { bg: '#F2D4D4', text: '#7E3030', label: 'Rose'   },
    purple: { bg: '#E8D4F2', text: '#5C3070', label: 'Purple' },
    slate:  { bg: '#E0E4E8', text: '#3C4856', label: 'Slate'  },
};

export function avatarStyle(colorKey) {
    const c = AVATAR_COLORS[colorKey] ?? AVATAR_COLORS.sage;
    return { backgroundColor: c.bg, color: c.text };
}

export function initials(name) {
    return (name ?? '')
        .split(' ')
        .map(w => w[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}
