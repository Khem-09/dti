function showPart(partNumber) {
    // Hide all parts
    document.getElementById('part1').classList.add('d-none');
    document.getElementById('part2').classList.add('d-none');
    document.getElementById('part3').classList.add('d-none');
    
    // Show the selected part
    document.getElementById('part' + partNumber).classList.remove('d-none');
    
    // Reset the dropdown if going back to home
    if(partNumber === 1) {
        document.getElementById('provinceSelect').selectedIndex = 0;
    }
}
window.onload = function() {
    const urlParams = new URLSearchParams(window.location.search);
    const targetPart = urlParams.get('part');
    
    if (targetPart === '3') {
        showPart(3);
    }
    const dropzone = document.getElementById('dropzoneBox');
    const fileInput = document.getElementById('fileInput');
    const uploadForm = document.getElementById('uploadForm');
    // 1. Click to open file manager
    dropzone.addEventListener('click', () => fileInput.click());
    // 2. Visual effect when dragging over
    dropzone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropzone.style.backgroundColor = "#e9ecef";
        dropzone.style.borderColor = "#8B0000";
    });
    dropzone.addEventListener('dragleave', () => {
        dropzone.style.backgroundColor = "transparent";
        dropzone.style.borderColor = "#ccc";
    });
    // 3. Handle Dropped File
    dropzone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropzone.style.backgroundColor = "transparent";
        
        if (e.dataTransfer.files.length) {
            fileInput.files = e.dataTransfer.files;
            uploadForm.submit(); // Automatically submit form
        }
    });
    // 4. Handle Clicked File
    fileInput.addEventListener('change', () => {
        if (fileInput.files.length) {
            uploadForm.submit(); // Automatically submit form
        }
    });
    document.getElementById('fileInput').addEventListener('change', function() {
            if (this.files.length) { document.getElementById('uploadForm').submit(); }
        });

        dropzone.addEventListener('dragover', (e) => { e.preventDefault(); dropzone.style.backgroundColor = "#e9ecef"; });
        dropzone.addEventListener('dragleave', () => { dropzone.style.backgroundColor = "transparent"; });
        dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            if (e.dataTransfer.files.length) {
                document.getElementById('fileInput').files = e.dataTransfer.files;
                document.getElementById('uploadForm').submit();
            }
        });
};