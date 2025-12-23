import AccessList from "./components/widgets/AccessList.vue";

Statamic.booting(() => {
    Statamic.$components.register('AccessList', AccessList);
});
