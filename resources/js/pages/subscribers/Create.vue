<script setup lang="ts">
import { Head, useHttp } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import SubscriberController from '@/actions/App/Http/Controllers/Api/V1/SubscriberController';

type SubscriberStatus = 'subscribed' | 'unsubscribed';

type SubscriberForm = {
    email: string;
    status: SubscriberStatus;
};

const successMessage = ref('');
const requestErrorMessage = ref('');

const form = useHttp<SubscriberForm>(
    'post',
    SubscriberController.url(),
    {
        email: '',
        status: 'subscribed',
    },
);

const errors = computed(() => form.errors);
const processing = computed(() => form.processing);

const submit = async (): Promise<void> => {
    successMessage.value = '';
    requestErrorMessage.value = '';

    try {
        await form.submit({
            onSuccess: (_response, httpResponse) => {
                if (httpResponse.status === 202) {
                    successMessage.value =
                        'Got it — we are processing your subscription.';
                    form.reset('email');
                }
            },
            onError: () => {
                successMessage.value = '';
            },
            onHttpException: (response) => {
                if (response.status === 429) {
                    requestErrorMessage.value =
                        'Too many requests — please slow down and retry shortly.';

                    return;
                }

                if (response.status === 503) {
                    requestErrorMessage.value =
                        'Service temporarily unavailable — please retry in a few seconds.';

                    return;
                }

                requestErrorMessage.value =
                    'We could not process this request. Please try again.';
            },
            onNetworkError: () => {
                requestErrorMessage.value =
                    'We could not reach the subscription service. Please try again.';
            },
        });
    } catch {
        if (!requestErrorMessage.value) {
            requestErrorMessage.value =
                'We could not process this request. Please try again.';
        }
    }
};

defineOptions({
    layout: {
        title: 'Create a subscriber',
        description: 'Enter the subscriber details below',
    },
});
</script>

<template>
    <div class="contents">
        <Head title="Create a subscriber" />

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="email"
                        name="email"
                        placeholder="email@example.com"
                        :aria-invalid="Boolean(errors.email)"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="status">Status</Label>
                    <select
                        id="status"
                        v-model="form.status"
                        name="status"
                        class="border-input bg-background ring-offset-background focus-visible:ring-ring flex h-9 w-full rounded-md border px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:bg-input/30 dark:aria-invalid:ring-destructive/40"
                        :tabindex="2"
                        :disabled="processing"
                        :aria-invalid="Boolean(errors.status)"
                    >
                        <option value="subscribed">Subscribed</option>
                        <option value="unsubscribed">Unsubscribed</option>
                    </select>
                    <InputError :message="errors.status" />
                </div>

                <div
                    v-if="requestErrorMessage"
                    class="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                    role="alert"
                >
                    {{ requestErrorMessage }}
                </div>

                <div
                    v-if="successMessage"
                    class="rounded-md border border-green-600/30 bg-green-600/10 px-3 py-2 text-sm font-medium text-green-700 dark:text-green-400"
                    role="status"
                    aria-live="polite"
                >
                    {{ successMessage }}
                </div>

                <Button
                    type="submit"
                    class="mt-2 w-full"
                    :tabindex="3"
                    :disabled="processing"
                    data-test="create-subscriber-button"
                >
                    <Spinner v-if="processing" />
                    Create subscriber
                </Button>
            </div>
        </form>
    </div>
</template>
