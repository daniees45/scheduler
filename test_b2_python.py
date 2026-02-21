
import os
import time
from b2_handler import B2Handler

def test_b2_operations():
    print("Testing B2 Handler...")
    b2 = B2Handler()
    
    if not b2.s3:
        print("FAILED: B2 Client not initialized")
        return
    
    # 1. Test Upload
    test_content = f"Test B2 Python {time.time()}"
    test_file = "test_b2_python.txt"
    with open(test_file, "w") as f:
        f.write(test_content)
    
    upload_key = "tests/test_b2_python.txt"
    print(f"Uploading {test_file} to {upload_key}...")
    if b2.upload_file(test_file, upload_key):
        print("PASS: Upload successful")
    else:
        print("FAILED: Upload failed")
        return

    # 2. Test Download
    download_path = "test_b2_downloaded.txt"
    if os.path.exists(download_path):
        os.remove(download_path)
        
    print(f"Downloading {upload_key} to {download_path}...")
    if b2.download_file(upload_key, download_path):
        print("PASS: Download successful")
        with open(download_path, "r") as f:
            content = f.read()
            if content == test_content:
                print(f"PASS: Content matches: {content}")
            else:
                print(f"FAILED: Content mismatch: {content} != {test_content}")
    else:
        print("FAILED: Download failed")

    # Cleanup
    if os.path.exists(test_file): os.remove(test_file)
    if os.path.exists(download_path): os.remove(download_path)
    
if __name__ == "__main__":
    test_b2_operations()
