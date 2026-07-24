// Dashboard home page (feature 028): the authenticated root landing. A "Dashboard" title above two
// launcher tiles — New Tour (with a map illustration) and Manage drivers — each linking to its
// workflow. Guests never reach this page (the root route serves them the welcome page instead).
import { Head } from '@inertiajs/react';
import { Users } from 'lucide-react';
import { DashboardTile } from '@/components/dashboard/dashboard-tile';

// A theme-aware, inline map illustration for the New Tour tile — always renders (no broken-image),
// and recolours with the palette in light/dark.
function MapIllustration() {
    return (
        <div className="size-full bg-muted">
            <svg
                viewBox="0 0 160 90"
                className="size-full"
                fill="none"
                aria-hidden="true"
                preserveAspectRatio="xMidYMid slice"
            >
                <g className="stroke-border" strokeWidth="2">
                    <path d="M0 30 H160" />
                    <path d="M0 62 H160" />
                    <path d="M45 0 V90" />
                    <path d="M110 0 V90" />
                </g>
                <path
                    d="M20 75 C50 60 55 40 85 38 S130 28 145 15"
                    className="stroke-primary"
                    strokeWidth="3"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeDasharray="1 7"
                />
                <circle cx="20" cy="75" r="4" className="fill-primary" />
                <circle cx="145" cy="15" r="4" className="fill-primary" />
            </svg>
        </div>
    );
}

export default function Home() {
    return (
        <>
            <Head title="Dashboard" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <h1 className="text-2xl font-semibold">Dashboard</h1>

                <div className="grid gap-4 sm:grid-cols-2">
                    <DashboardTile
                        label="New Tour"
                        href="/tour"
                        illustration={<MapIllustration />}
                    />
                    <DashboardTile
                        label="Manage drivers"
                        href="/driver"
                        icon={Users}
                    />
                </div>
            </div>
        </>
    );
}
