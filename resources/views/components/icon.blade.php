@props(['name', 'size' => 20])
<svg {{ $attributes->merge(['class' => 'icon']) }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
@switch($name)
    @case('logo')
        <path d="M4 21V8l2-1V4h2.5v2h2V4h3v2h2V4H18v3l2 1v13h-5.5v-4.5a2.5 2.5 0 0 0-5 0V21z"/>
        @break
    @case('grip')
        <circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>
        @break
    @case('table')
        <rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M3 15h18M9 10v10"/>
        @break
    @case('chart')
        <path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>
        @break
    @case('folder')
        <path d="M20 17a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3.9a2 2 0 0 1-1.69-.9l-.81-1.2a2 2 0 0 0-1.67-.9H8a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2Z"/>
        <path d="M2 8v11a2 2 0 0 0 2 2h14"/>
        @break
    @case('reactor')
        <path d="M6 21c1.2-3 1.6-6.2 1-9.5-.3-1.7-.9-3-1.5-4h13c-.6 1-1.2 2.3-1.5 4-.6 3.3-.2 6.5 1 9.5Z"/>
        <path d="M9 4.5c0-1.1.9-2 2-2 .6 0 1.1.3 1.5.7a2 2 0 0 1 3.5 1.3"/>
        <circle cx="12" cy="15" r="1.2"/>
        <path d="M10.6 17.4 9.5 19.3M13.4 17.4l1.1 1.9M12 13.6V12"/>
        @break
    @case('plane')
        <path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/>
        @break
    @case('cctv')
        <path d="M16.75 12h3.632a1 1 0 0 1 .894 1.447l-2.034 4.069a1 1 0 0 1-1.708.134l-2.124-2.97"/>
        <path d="M17.106 9.053a1 1 0 0 1 .447 1.341l-3.106 6.211a1 1 0 0 1-1.342.447L3.61 12.3a2.92 2.92 0 0 1-1.3-3.91L3.69 5.6a2.92 2.92 0 0 1 3.92-1.3z"/>
        <path d="M2 19h3.76a2 2 0 0 0 1.8-1.1L9 15"/>
        <path d="M2 21v-4"/>
        <path d="M7 9h.01"/>
        @break
    @case('health')
        <path d="M9 3h6v6h6v6h-6v6H9v-6H3V9h6z"/>
        @break
    @case('truck')
        <path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/>
        <path d="M15 18H9"/>
        <path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/>
        <circle cx="17" cy="18" r="2"/>
        <circle cx="7" cy="18" r="2"/>
        @break
    @case('zap')
        <path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>
        @break
    @case('cloud')
        <path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/>
        @break
    @case('star')
        <path d="M12 2.5l2.94 5.96 6.56.95-4.75 4.63 1.12 6.54L12 17.5l-5.87 3.08 1.12-6.54L2.5 9.41l6.56-.95z"/>
        @break
    @case('plus')
        <path d="M12 5v14M5 12h14"/>
        @break
    @case('chevron-right')
        <path d="m9 18 6-6-6-6"/>
        @break
    @case('list-checks')
        <path d="m3 17 2 2 4-4"/><path d="m3 7 2 2 4-4"/><path d="M13 6h8"/><path d="M13 12h8"/><path d="M13 18h8"/>
        @break
    @case('play')
        <circle cx="12" cy="12" r="9.5"/>
        <path d="M10 8.5v7l5.5-3.5z" fill="currentColor"/>
        @break
    @case('workflow')
        <circle cx="5" cy="5" r="2.5"/>
        <path d="M4.2 3.9v2.2L6 5z" fill="currentColor" stroke="none"/>
        <path d="M5 7.5V17a2 2 0 0 0 2 2h3"/>
        <path d="M5 11h5"/>
        <circle cx="12.5" cy="11" r="2.5"/><circle cx="19.5" cy="11" r="2.5"/>
        <path d="M15 11h2"/>
        <circle cx="12.5" cy="19" r="2.5"/><circle cx="19.5" cy="19" r="2.5"/>
        <path d="M15 19h2"/>
        @break
    @case('at')
        <circle cx="12" cy="12" r="4"/>
        <path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-4 8"/>
        @break
    @case('check')
        <circle cx="12" cy="12" r="9.5"/>
        <path d="m8 12.5 2.8 2.8L16.5 9.5"/>
        @break
    @case('x')
        <path d="M18 6 6 18M6 6l12 12"/>
        @break
    @case('more-vertical')
        <circle cx="12" cy="5" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="12" cy="19" r="1.4"/>
        @break
    @case('more-horizontal')
        <circle cx="5" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="19" cy="12" r="1.4"/>
        @break
    @case('bell')
        <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>
        <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>
        @break
    @case('settings')
        <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/>
        <circle cx="12" cy="12" r="3"/>
        @break
    @case('user')
        <circle cx="12" cy="12" r="10"/>
        <circle cx="12" cy="10" r="3"/>
        <path d="M7 20.662V19a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v1.662"/>
        @break
    @case('search')
        <circle cx="11" cy="11" r="7"/>
        <path d="m20 20-4-4"/>
        @break
    @case('panel-left')
        <rect x="3" y="3" width="18" height="18" rx="2"/>
        <path d="M9 3v18"/>
        <path d="M5.5 7h1M5.5 10h1M5.5 13h1"/>
        @break
    @case('minus')
        <path d="M5 12h14"/>
        @break
    @case('square')
        <rect x="5" y="5" width="14" height="14" rx="1.5"/>
        @break
    @case('copy')
        <rect x="8" y="8" width="13" height="13" rx="2"/>
        <path d="M4 16a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2"/>
        @break
    @case('expand')
        <path d="M4 6h16M4 12h16M4 18h10"/>
        @break
    @case('widen')
        <path d="M3 4v16M21 4v16M7 12h10M10 9l-3 3 3 3M14 9l3 3-3 3"/>
        @break
    @case('narrow')
        <path d="M12 4v16M3 12h6M6 9l3 3-3 3M21 12h-6M18 9l-3 3 3 3"/>
        @break
    @case('monitor')
        <rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>
        @break
    @case('sun')
        <circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
        @break
    @case('moon')
        <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>
        @break
    @case('keyboard')
        <rect x="2" y="5" width="20" height="14" rx="2"/><path d="M6 9h.01M10 9h.01M14 9h.01M18 9h.01M6 13h.01M18 13h.01M10 13h4M7 16h10"/>
        @break
    @case('rotate')
        <path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>
        @break
    @case('ticket')
        <path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2M13 17v2M13 11v2"/>
        @break
    @case('inbox')
        <path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>
        @break
    @case('message')
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        @break
    @case('log-out')
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>
        @break
    @case('trash')
        <path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6"/>
        @break
    @case('grid')
        <rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>
        @break
    @case('tag')
        <path d="M12.59 2.59A2 2 0 0 0 11.17 2H4a2 2 0 0 0-2 2v7.17a2 2 0 0 0 .59 1.42l8.7 8.7a2.43 2.43 0 0 0 3.42 0l6.58-6.58a2.43 2.43 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r="1.5"/>
        @break
    @case('arrow-left')
        <path d="m12 19-7-7 7-7M19 12H5"/>
        @break
    @case('clock')
        <circle cx="12" cy="12" r="9.5"/><path d="M12 7v5l3 2"/>
        @break
    @case('dot')
        <circle cx="12" cy="12" r="3" fill="currentColor" stroke="none"/>
        @break
    @case('link')
        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
        @break
    @case('cube')
        <path d="M21 7.5 12 2.5 3 7.5v9l9 5 9-5z"/><path d="M3 7.5l9 5 9-5M12 12.5v9"/>
        @break
    @case('blueprint')
        <rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9M13 13h4M13 17h4"/>
        @break
    @case('image')
        <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/>
        @break
@endswitch
</svg>
