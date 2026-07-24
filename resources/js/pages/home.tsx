// Dashboard home page (feature 028): the authenticated root landing. A "Dashboard" title above two
// launcher tiles — New Tour (with a map illustration) and Manage drivers — each linking to its
// workflow. Guests never reach this page (the root route serves them the welcome page instead).
import { Head } from '@inertiajs/react';
import { Map, Users } from 'lucide-react';
import { DashboardTile } from '@/components/dashboard/dashboard-tile';

export default function Home() {
    return (
        <>
            <Head title="Dashboard" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <h1 className="text-2xl font-semibold">Dashboard</h1>

                <div className="grid gap-4 sm:grid-cols-2">
                    <DashboardTile label="New Tour" href="/tour" icon={Map} />
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
