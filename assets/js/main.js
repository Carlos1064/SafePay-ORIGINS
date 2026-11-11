// JavaScript propio de SafePay.
// Las validaciones de negocio viven en el servidor (PHP); aquí solo mejoras de interfaz.

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-confirm]').forEach((formulario) => {
        formulario.addEventListener('submit', (evento) => {
            const mensaje = formulario.getAttribute('data-confirm') || '¿Confirma esta acción?';

            if (!window.confirm(mensaje)) {
                evento.preventDefault();
            }
        });
    });

    document.querySelectorAll('[data-print]').forEach((boton) => {
        boton.addEventListener('click', () => window.print());
    });
});
