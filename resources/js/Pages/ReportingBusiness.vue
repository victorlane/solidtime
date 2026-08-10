<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import ReportingBusiness from '@/Components/Common/Reporting/ReportingBusiness.vue';
</script>

<template>
    <AppLayout title="Business" data-testid="reporting_business_view" class="overflow-hidden">
        <ReportingBusiness></ReportingBusiness>
    </AppLayout>
</template>
