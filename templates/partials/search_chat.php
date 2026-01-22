<!-- Panneau Chat IA (sidebar ou modal) -->
<div id="ai-chat-panel" class="hidden fixed right-0 top-0 h-full w-96 bg-white shadow-2xl z-40 flex flex-col">
    <div class="p-4 bg-purple-600 text-white flex justify-between items-center">
        <h3 class="font-semibold"><i class="fas fa-robot mr-2"></i>Assistant K-Docs</h3>
        <button onclick="toggleChatPanel()" class="text-white hover:text-gray-200">
            <i class="fas fa-times"></i>
        </button>
    </div>
    
    <div id="chat-messages" class="flex-1 overflow-y-auto p-4 space-y-4">
        <div class="text-center text-gray-500 py-8">
            <i class="fas fa-comments text-4xl mb-2"></i>
            <p>Posez une question sur vos documents</p>
            <p class="text-sm mt-2">Exemples :</p>
            <div class="mt-2 space-y-1 text-sm">
                <button class="example-question text-purple-600 hover:underline block">Où est la référence ABC123 ?</button>
                <button class="example-question text-purple-600 hover:underline block">Total factures Swisscom 2024</button>
                <button class="example-question text-purple-600 hover:underline block">Résume le dernier document</button>
            </div>
        </div>
    </div>
    
    <div class="p-4 border-t">
        <form id="chat-form" class="flex gap-2">
            <input 
                type="text" 
                id="chat-input"
                class="flex-1 border rounded-lg px-3 py-2 focus:ring-2 focus:ring-purple-500"
                placeholder="Posez votre question..."
            >
            <button type="submit" class="bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700">
                <i class="fas fa-paper-plane"></i>
            </button>
        </form>
    </div>
</div>

<!-- Bouton flottant pour ouvrir le chat -->
<button 
    id="chat-toggle-btn"
    onclick="toggleChatPanel()"
    class="fixed bottom-6 right-6 bg-purple-600 text-white w-14 h-14 rounded-full shadow-lg hover:bg-purple-700 z-30 flex items-center justify-center"
>
    <i class="fas fa-robot text-xl"></i>
</button>
