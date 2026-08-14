<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { create } from '@/routes/subscribers';

type Subscriber = {
    id: number;
    email: string;
    status: 'subscribed' | 'unsubscribed';
    created_at: string;
};

type SimplePaginator = {
    data: Subscriber[];
    prev_page_url: string | null;
    next_page_url: string | null;
    from: number | null;
    to: number | null;
};

defineProps<{
    subscribers: SimplePaginator;
}>();

const formatDate = (value: string): string =>
    new Date(value).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
</script>

<template>
    <div class="min-h-svh bg-background p-6 md:p-10">
        <Head title="Subscribers" />

        <div class="mx-auto w-full max-w-3xl">
            <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-medium">Subscribers</h1>
                    <p class="text-sm text-muted-foreground">
                        Everyone who has been added so far.
                    </p>
                </div>

                <Button as-child>
                    <Link :href="create()" data-test="add-subscriber-link">
                        Add subscriber
                    </Link>
                </Button>
            </div>

            <div
                v-if="subscribers.data.length === 0"
                class="rounded-lg border border-dashed p-10 text-center"
                data-test="subscribers-empty"
            >
                <p class="text-sm font-medium">No subscribers yet</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Add the first one to see it listed here.
                </p>
            </div>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm" data-test="subscribers-table">
                    <thead class="border-b bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Email</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Added</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="subscriber in subscribers.data"
                            :key="subscriber.id"
                            class="border-b last:border-b-0"
                        >
                            <td class="px-4 py-3">{{ subscriber.email }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        subscriber.status === 'subscribed'
                                            ? 'bg-green-600/10 text-green-700 dark:text-green-400'
                                            : 'bg-muted text-muted-foreground'
                                    "
                                >
                                    {{ subscriber.status }}
                                </span>
                            </td>
                            <td
                                class="px-4 py-3 whitespace-nowrap text-muted-foreground"
                            >
                                {{ formatDate(subscriber.created_at) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="subscribers.prev_page_url || subscribers.next_page_url"
                class="mt-4 flex items-center justify-between"
            >
                <Button
                    v-if="subscribers.prev_page_url"
                    as-child
                    variant="outline"
                >
                    <Link :href="subscribers.prev_page_url">Previous</Link>
                </Button>
                <span v-else />

                <Button
                    v-if="subscribers.next_page_url"
                    as-child
                    variant="outline"
                >
                    <Link :href="subscribers.next_page_url">Next</Link>
                </Button>
            </div>
        </div>
    </div>
</template>
