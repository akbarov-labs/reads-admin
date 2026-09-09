{{-- Always-visible way back to the dashboard, independent of the sidebar —
     a translator's sidebar is hidden entirely (see AdminPanelProvider), so
     the sidebar's own logo/home link isn't available to them. --}}
<x-filament::icon-button
    icon="heroicon-o-home"
    icon-size="lg"
    color="gray"
    :href="filament()->getHomeUrl()"
    tag="a"
    label="Dashboard"
/>
