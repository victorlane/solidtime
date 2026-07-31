import { usePage } from '@inertiajs/vue3';
import { getDayJsInstance } from '@/packages/ui/src/utils/time';

export function isBillingActivated() {
    const page = usePage<{
        has_billing_extension: boolean;
    }>();

    return page.props.has_billing_extension;
}

/**
 * SILENCE_PREMIUM hides the upgrade nags. It does not unlock anything — the API still
 * enforces the plan, so premium actions keep explaining themselves via the upgrade modal.
 */
export function isPremiumSilenced() {
    const page = usePage<{
        silence_premium: boolean;
    }>();

    return page.props.silence_premium === true;
}

export function isInvoicingActivated() {
    const page = usePage<{
        has_invoicing_extension: boolean;
    }>();

    return page.props.has_invoicing_extension;
}

export function isInTrial() {
    const page = usePage<{
        billing: {
            has_trial: boolean;
        };
    }>();

    return page.props.billing.has_trial;
}

export function daysLeftInTrial() {
    const page = usePage<{
        billing: {
            trial_until: string;
        };
    }>();

    return (
        getDayJsInstance()(page.props.billing.trial_until).diff(getDayJsInstance()(), 'days') + 1
    );
}

export function isBlocked() {
    const page = usePage<{
        billing: {
            is_blocked: boolean;
        };
    }>();

    return page.props.billing.is_blocked;
}

export function isFreePlan() {
    return !hasActiveSubscription() && !isInTrial();
}

export function hasActiveSubscription() {
    const page = usePage<{
        billing: {
            has_subscription: boolean;
        };
    }>();

    return page.props.billing.has_subscription;
}

export function isAllowedToPerformPremiumAction() {
    return (
        !isBillingActivated() ||
        (isBillingActivated() && hasActiveSubscription()) ||
        (isBillingActivated() && isInTrial())
    );
}
