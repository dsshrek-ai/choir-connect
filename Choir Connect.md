# Choir Connect

App Options

- Similar in architecture to choir-app.

- Schedule
  
  - Options for Date/Time Location (with map link)
  
  - Sequence by rehearsal date and time descending.
    
    - Include past events.
  
  - Entry Instructions, etc.

- Member List
  
  - List Name, Address, Phone, eMail
    
    - Option for singer to hide Address, Phone and/or email.
    
    - Include picture 
    
    - Have tags for calling and texting members. Include map feature for driving directtions, etc.

- Rehearsal Tracks
  
  - Database: Use MyDataWorld. Table:RehearsalTracks 
    
        For the song storage I have this structure:
    
            Root\choir-connect\DeciBelles\\Songs\ and then the particular song folders for each song. The first choir we are creating this app for  is The DeciBelles.
    
    - Index
    
    - Choir Number
    
    - Program number
    
    - Song Number
    
    - Track sequence
    
    - Track short description
    
    - Track name
  
  - Table: Choir
    
    - Index
    
    - Choir Number
    
    - Choir Name
  
  - Table: Program
    
    - Index
    
    - Program Number
    
    - Program Name
    
    - Program Sequence
  
  - Features: With the tracks they need to be displayed with the most recent program on top. 
    
    - Songs need to be filtered by program. The user sees a list with the newest program on top, then they tap to enter that program.
  
  - User App Access. The User's email from the apps hub keys their access to the choirs. There will need to be a table that shows Choir#, User. There will need to be an admin kiosk that has access granted so someone can assign users to choirs. A user can be in multiple choirs, so if there is more than 1 choir that they are in, they will need to choose the choir at the top.
