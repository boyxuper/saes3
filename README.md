saes3
=====

stream wrapper for sae(sina app engine) storage

enable protocol:
 - saes3://domain/path/to/file
 - saes3gz://domain/path/to/file

supported method:
 - fopen(with modes)/fclose/fread/fwrite
 - flock(_sae_flock) #see code
 - file_put_content(get)
 - fstat & stat
 - rename/unlink/mkdir
 - [chunk mode file read & write]
 - [identify file or folder or both]
 - [auto gz compress/decompress]

***SAE storage allows same name (both file & folder) at same time***
