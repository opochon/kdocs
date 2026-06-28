<!-- Panneau Chat IA (sidebar ou modal) -->
<div id="ai-chat-panel" class="hidden fixed right-0 top-0 h-full w-96 z-40 flex flex-col" style="background:var(--surface);box-shadow:var(--shadow-pop)">
    <div class="p-4 flex justify-between items-center" style="background:var(--primary);color:var(--primary-ink)">
        <h3 class="font-semibold"><i class="fas fa-robot mr-2"></i>Assistant K-Docs</h3>
        <button onclick="toggleChatPanel()" style="color:var(--primary-ink)">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <div id="chat-messages" class="flex-1 overflow-y-auto p-4 space-y-4">
        <div class="text-center py-8" style="color:var(--dim)">
            <i class="fas fa-comments text-4xl mb-2"></i>
            <p>Posez une question sur vos documents</p>
            <p class="text-sm mt-2">Exemples :</p>
            <div class="mt-2 space-y-1 text-sm">
                <button class="example-question hover:underline block" style="color:var(--accent)">Où est la référence ABC123 ?</button>
                <button class="example-question hover:underline block" style="color:var(--accent)">Total factures Swisscom 2024</button>
                <button class="example-question hover:underline block" style="color:var(--accent)">Résume le dernier document</button>
            </div>
        </div>
    </div>

    <div class="p-4 border-t">
        <form id="chat-form" class="flex gap-2">
            <input
                type="text"
                id="chat-input"
                class="form-input flex-1"
                placeholder="Posez votre question..."
            >
            <button type="submit" class="btn-primary px-4 py-2 rounded-lg">
                <i class="fas fa-paper-plane"></i>
            </button>
        </form>
    </div>
</div>

<!-- Bouton flottant pour ouvrir le chat -->
<button
    id="chat-toggle-btn"
    onclick="toggleChatPanel()"
    class="btn-primary fixed bottom-6 right-6 w-14 h-14 rounded-full z-30 flex items-center justify-center"
    style="box-shadow:var(--shadow-pop)"
>
    <i class="fas fa-robot text-xl"></i>
</button>
